<?php
declare(strict_types=1);

/**
 * Generates 24 months of payroll (Oct 2024 - Sep 2026) for South Valley Poultry
 * Farm: ~14 employees across the farm's departments, one monthly payroll period
 * per month, all advanced through to Paid (posting wages + employer statutory
 * contributions to AIS and allocating labor cost into batch_costs).
 *
 * Supersedes the flat, non-itemized "Labor Expense" farm-wide transactions and
 * 'Labor' batch_costs rows that database/seed/two_year_realistic_demo_data.php
 * generated earlier — this script deletes exactly those (and nothing else)
 * before generating real, employee-level payroll, so labor cost is never
 * counted twice.
 *
 * Run: php database/seed/payroll_two_year_data.php
 * Re-run safe: deletes its own prior output (by payroll_periods date range)
 * and the old synthetic Labor rows first, every time.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../../shared/includes/bootstrap.php';

$_SESSION['user'] = ['user_id' => 0, 'username' => 'data_migration', 'display_name' => 'Payroll Generator', 'role' => 'System', 'system_access' => 'ais'];

$pdo = database_connection();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'sia2') {
    fwrite(STDERR, "Refusing to run: connected database is '$dbName', expected 'sia2'.\n");
    exit(1);
}

mt_srand(20261018);
function r(float $min, float $max): float { return $min + mt_rand(0, 1000000) / 1000000 * ($max - $min); }
function ri(int $min, int $max): int { return mt_rand($min, $max); }

const WINDOW_START = '2024-10-01';
const WINDOW_END = '2026-09-30';

echo "=== South Valley Poultry Farm — payroll generator ===\n";

$pdo->beginTransaction();
try {
    // --- Remove exactly what this script (or the old overhead-split logic) previously
    // generated, so re-running never duplicates and old synthetic Labor cost never
    // coexists with real payroll-driven Labor cost.
    $oldLaborTxnIds = $pdo->query(
        "SELECT transaction_id FROM financial_transactions WHERE category = 'Labor Expense' AND flock_id IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($oldLaborTxnIds !== []) {
        $in = implode(',', array_map('intval', $oldLaborTxnIds));
        $pdo->exec("DELETE FROM financial_transactions WHERE transaction_id IN ($in)");
    }
    $pdo->exec("DELETE FROM batch_costs WHERE cost_category = 'Labor'");
    $pdo->exec("DELETE FROM payroll_items");
    $pdo->exec("DELETE FROM payroll_periods");
    $pdo->exec("DELETE FROM employees");
    echo "Cleared old synthetic Labor Expense/batch_costs rows and any prior payroll data.\n";

    // --- Employee roster -----------------------------------------------------------
    // Rates are modeling assumptions for this fictional farm (South Cotabato daily-
    // wage range, not an exact regional wage order citation).
    // Sized to this farm's actual scale (~500-bird broiler batches every 2 weeks,
    // one ~200-300 hen layer flock at a time) — a crew of 9, not a large multi-house
    // commercial operation's full headcount, to keep total labor cost plausible
    // against this farm's actual revenue rather than a fixed "medium/large farm"
    // headcount template applied regardless of scale.
    $employees = [
        ['code' => 'EMP-0001', 'name' => 'Ramon Castillo', 'title' => 'Farm Manager', 'dept' => 'Farm Management', 'pay_type' => 'Monthly', 'rate' => 28000, 'hired' => '2024-09-20'],
        ['code' => 'EMP-0003', 'name' => 'Danilo Reyes', 'title' => 'Broiler House Attendant', 'dept' => 'Broiler Operations', 'pay_type' => 'Daily', 'rate' => 440, 'hired' => '2024-09-25'],
        ['code' => 'EMP-0004', 'name' => 'Marites Santos', 'title' => 'Broiler House Attendant', 'dept' => 'Broiler Operations', 'pay_type' => 'Daily', 'rate' => 440, 'hired' => '2024-09-25'],
        ['code' => 'EMP-0006', 'name' => 'Rosalinda Gomez', 'title' => 'Layer House Attendant', 'dept' => 'Layer Operations', 'pay_type' => 'Daily', 'rate' => 440, 'hired' => '2024-09-25'],
        ['code' => 'EMP-0008', 'name' => 'Corazon Villanueva', 'title' => 'Feed & Inventory Clerk', 'dept' => 'Feed & Inventory', 'pay_type' => 'Daily', 'rate' => 440, 'hired' => '2024-09-20'],
        ['code' => 'EMP-0009', 'name' => 'Arnel Domingo', 'title' => 'Maintenance Worker', 'dept' => 'Maintenance', 'pay_type' => 'Daily', 'rate' => 440, 'hired' => '2024-10-15'],
        ['code' => 'EMP-0010', 'name' => 'Lourdes Aquino', 'title' => 'Egg Collection & Packing Worker', 'dept' => 'Egg Collection & Packing', 'pay_type' => 'Daily', 'rate' => 410, 'hired' => '2024-09-25'],
        ['code' => 'EMP-0012', 'name' => 'Virgilio Pascual', 'title' => 'Harvest & Loading Worker', 'dept' => 'Harvest & Loading', 'pay_type' => 'Daily', 'rate' => 420, 'hired' => '2024-09-25'],
        ['code' => 'EMP-0014', 'name' => 'Imelda Torres', 'title' => 'Administrative & Accounting Staff', 'dept' => 'Administration & Accounting', 'pay_type' => 'Monthly', 'rate' => 17000, 'hired' => '2024-09-20'],
    ];

    $insertEmployee = $pdo->prepare(
        'INSERT INTO employees (employee_code, full_name, job_title, department, employment_status, pay_type, basic_rate, date_hired)
         VALUES (?, ?, ?, ?, \'Active\', ?, ?, ?)'
    );
    $employeeIds = [];
    foreach ($employees as $emp) {
        $insertEmployee->execute([$emp['code'], $emp['name'], $emp['title'], $emp['dept'], $emp['pay_type'], $emp['rate'], $emp['hired']]);
        $employeeIds[$emp['code']] = (int) $pdo->lastInsertId();
    }
    echo "Inserted " . count($employees) . " employees.\n";

    // --- Monthly payroll periods, Oct 2024 - Sep 2026 -------------------------------
    // Direct-assignment departments allocate to a specific currently-active flock of
    // the matching kind (broiler vs layer) each month when one exists; everyone else
    // (and direct-assignment staff in a month with no matching active flock) falls
    // back to Overhead, which spreads proportionally across all active flocks.
    $directDepartments = ['Broiler Operations' => 'SVPF-BR-%', 'Layer Operations' => 'SVPF-LY-%', 'Egg Collection & Packing' => 'SVPF-LY-%', 'Harvest & Loading' => 'SVPF-BR-%'];

    $insertPeriod = $pdo->prepare(
        "INSERT INTO payroll_periods (period_start, period_end, pay_date, status, created_by, approved_by, approved_at, paid_by, paid_at)
         VALUES (?, ?, ?, 'Draft', 'ais_admin', 'ais_admin', ?, 'ais_admin', ?)"
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO payroll_items
            (payroll_period_id, employee_id, days_worked, basic_pay, overtime_hours, overtime_pay, allowances,
             sss_employee, philhealth_employee, pagibig_employee, other_deductions,
             sss_employer, philhealth_employer, pagibig_employer, allocation_type, allocation_flock_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $cursor = WINDOW_START;
    $periodsCreated = 0;
    $findFlockStatement = $pdo->prepare(
        "SELECT f.flock_id FROM flocks f
         JOIN production_records p ON p.flock_id = f.flock_id AND p.deleted_at IS NULL
         WHERE f.deleted_at IS NULL AND f.batch_code LIKE ?
         GROUP BY f.flock_id HAVING MIN(p.record_date) <= ? AND MAX(p.record_date) >= ?
         ORDER BY RAND() LIMIT 1"
    );

    while ($cursor <= WINDOW_END) {
        $periodStart = $cursor;
        $periodEnd = (new DateTimeImmutable($cursor))->modify('last day of this month')->format('Y-m-d');
        if ($periodEnd > WINDOW_END) {
            $periodEnd = WINDOW_END;
        }
        // Pay/approval dates are clamped to WINDOW_END so the last period (whose
        // natural +2/+5 day offsets would otherwise fall in October 2026) never
        // leaks a transaction date past the dataset's own stated window.
        $payDate = min(WINDOW_END, (new DateTimeImmutable($periodEnd))->modify('+5 days')->format('Y-m-d'));
        $approvedAt = min(WINDOW_END, (new DateTimeImmutable($periodEnd))->modify('+2 days')->format('Y-m-d')) . ' 12:00:00';

        $insertPeriod->execute([$periodStart, $periodEnd, $payDate, $approvedAt, $payDate . ' 10:00:00']);
        $payrollPeriodId = (int) $pdo->lastInsertId();

        // Harvest/loading and collection-packing work gets busier (more overtime) in
        // months with more completed harvests — approximate via day-of-month spread.
        $isBusyMonth = ((int) substr($periodStart, 5, 2)) % 3 === 0;

        foreach ($employees as $emp) {
            if ($emp['hired'] > $periodEnd) {
                continue; // not yet hired this period
            }
            $employeeId = $employeeIds[$emp['code']];
            $employeeRow = ['pay_type' => $emp['pay_type'], 'basic_rate' => $emp['rate']];

            if ($emp['pay_type'] === 'Daily') {
                // Harvest & loading is inherently episodic work (needed around harvest
                // days, not every weekday), so it's modeled as part-time days-worked
                // rather than a full attendance month.
                $daysWorked = $emp['dept'] === 'Harvest & Loading' ? (float) ri(8, 14) : (float) ri(18, 26);
                $overtimeHours = $isBusyMonth && in_array($emp['dept'], ['Harvest & Loading', 'Egg Collection & Packing', 'Broiler Operations', 'Layer Operations'], true)
                    ? (float) ri(4, 14) : (mt_rand(1, 100) <= 30 ? (float) ri(0, 4) : 0.0);
            } else {
                $daysWorked = 0.0;
                $overtimeHours = 0.0;
            }
            $allowances = mt_rand(1, 100) <= 40 ? (float) ri(200, 500) : 0.0; // occasional meal/transport allowance
            $otherDeductions = mt_rand(1, 100) <= 10 ? (float) ri(200, 1000) : 0.0; // occasional cash-advance repayment

            $figures = payroll_calculate_item($employeeRow, $daysWorked, $overtimeHours, $allowances, $otherDeductions);

            $allocationType = 'Overhead';
            $allocationFlockId = null;
            if (isset($directDepartments[$emp['dept']])) {
                $findFlockStatement->execute([$directDepartments[$emp['dept']], $periodEnd, $periodStart]);
                $flockId = $findFlockStatement->fetchColumn();
                if ($flockId !== false) {
                    $allocationType = 'Flock';
                    $allocationFlockId = (int) $flockId;
                }
            }

            $insertItem->execute([
                $payrollPeriodId, $employeeId, $figures['days_worked'], $figures['basic_pay'], $figures['overtime_hours'], $figures['overtime_pay'],
                $figures['allowances'], $figures['sss_employee'], $figures['philhealth_employee'], $figures['pagibig_employee'], $figures['other_deductions'],
                $figures['sss_employer'], $figures['philhealth_employer'], $figures['pagibig_employer'], $allocationType, $allocationFlockId,
            ]);
        }

        // Pay it: post to AIS and allocate into batch_costs, exactly as the real
        // workflow would when a user clicks through Draft -> Reviewed -> Approved -> Paid.
        payroll_post_to_ais($pdo, $payrollPeriodId, 'ais_admin', $payDate);
        payroll_allocate_batch_costs($pdo, $payrollPeriodId);

        $periodsCreated++;
        $cursor = (new DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-d');
    }
    echo "Created and paid $periodsCreated monthly payroll periods.\n";

    // Batch_profitability cache depends on batch_costs; refresh it now that Labor
    // costs have changed from the old synthetic split to real payroll allocation.
    integrated_recalculate_all_batch_profitability($pdo);
    echo "batch_profitability cache refreshed.\n";

    $pdo->commit();
    echo "\n=== COMMIT SUCCESSFUL ===\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED — rolled back. Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

$counts = [];
foreach (['employees', 'payroll_periods', 'payroll_items'] as $table) {
    $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
$laborCost = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM batch_costs WHERE cost_category = 'Labor'")->fetchColumn();
$laborExpenseTxns = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_transactions WHERE category = 'Labor Expense'")->fetchColumn();

echo "\n=== RECORD COUNTS ===\n";
foreach ($counts as $table => $count) {
    echo sprintf("  %-20s %d\n", $table, $count);
}
echo sprintf("  %-20s %.2f\n", 'batch_costs Labor', $laborCost);
echo sprintf("  %-20s %.2f\n", 'Labor Expense (AIS)', $laborExpenseTxns);
echo "\nDone.\n";
