<?php
declare(strict_types=1);

// Shared payroll helpers (Part 8). Mirrors the conventions of pms_records.php /
// ais_records.php / integrated_records.php.
//
// Statutory contribution rates (SSS 15% total — 5% employee / 10% employer,
// salary-credit ceiling PHP 35,000; PhilHealth 5% total — 2.5% / 2.5%,
// ceiling PHP 100,000; Pag-IBIG 2% employee / 2% employer, capped at PHP 200
// each) reflect published 2025 rates. They are applied uniformly across the
// whole two-year dataset for simplicity. This is a modeling approximation for
// a fictional farm, not a certified payroll computation — re-verify against
// current official SSS/PhilHealth/Pag-IBIG circulars before any real use.

const PAYROLL_DEPARTMENTS = [
    'Farm Management', 'Broiler Operations', 'Layer Operations', 'Feed & Inventory',
    'Maintenance', 'Egg Collection & Packing', 'Harvest & Loading', 'Administration & Accounting',
];
const PAYROLL_OVERHEAD_DEPARTMENTS = ['Farm Management', 'Feed & Inventory', 'Maintenance', 'Administration & Accounting'];
const PAYROLL_PERIOD_STATUSES = ['Draft', 'Reviewed', 'Approved', 'Paid'];

function employee_status_badge(string $status): string
{
    return match ($status) {
        'Active' => 'success',
        'Inactive' => 'secondary',
        'Terminated' => 'danger',
        default => 'light',
    };
}

function payroll_period_status_badge(string $status): string
{
    return match ($status) {
        'Draft' => 'secondary',
        'Reviewed' => 'primary',
        'Approved' => 'warning',
        'Paid' => 'success',
        default => 'light',
    };
}

/**
 * Computes one payroll line item's figures from an employee's pay type/rate and the
 * period's worked days/overtime/allowances/other deductions. Pure calculation — does
 * not touch the database. Overtime uses the standard 25% ordinary-overtime premium;
 * an hourly rate is derived from the daily rate (÷8) or, for monthly-salaried staff,
 * from a 26-day/8-hour month (a standard, documented divisor — not a statutory one).
 */
function payroll_calculate_item(array $employee, float $daysWorked, float $overtimeHours, float $allowances, float $otherDeductions): array
{
    $isDaily = $employee['pay_type'] === 'Daily';
    $basicRate = (float) $employee['basic_rate'];
    $basicPay = $isDaily ? round($daysWorked * $basicRate, 2) : round($basicRate, 2);
    $hourlyRate = $isDaily ? ($basicRate / 8) : ($basicRate / 26 / 8);
    $overtimePay = round($overtimeHours * $hourlyRate * 1.25, 2);
    $allowances = round(max(0.0, $allowances), 2);
    $otherDeductions = round(max(0.0, $otherDeductions), 2);
    $grossPay = round($basicPay + $overtimePay + $allowances, 2);

    $sssCredit = min($grossPay, 35000.0);
    $philhealthCredit = min($grossPay, 100000.0);
    $pagibigCredit = min($grossPay, 10000.0);

    return [
        'days_worked' => round($daysWorked, 2),
        'basic_pay' => $basicPay,
        'overtime_hours' => round($overtimeHours, 2),
        'overtime_pay' => $overtimePay,
        'allowances' => $allowances,
        'other_deductions' => $otherDeductions,
        'sss_employee' => round($sssCredit * 0.05, 2),
        'sss_employer' => round($sssCredit * 0.10, 2),
        'philhealth_employee' => round($philhealthCredit * 0.025, 2),
        'philhealth_employer' => round($philhealthCredit * 0.025, 2),
        'pagibig_employee' => round($pagibigCredit * 0.02, 2),
        'pagibig_employer' => round($pagibigCredit * 0.02, 2),
    ];
}

/**
 * Posts a Paid payroll period's totals to AIS as two Expense transactions — gross
 * wages (what employees earned, including the portion later withheld on their
 * behalf) and employer statutory contributions (the farm's own additional cost).
 * Employee-side deductions are never posted separately: they are already inside
 * gross_pay, so adding them again would double-count. Guarded by
 * payroll_periods.wages_transaction_id so re-running this on an already-paid
 * period is a safe no-op, never a duplicate posting.
 */
function payroll_post_to_ais(PDO $pdo, int $payrollPeriodId, string $paidBy, ?string $payDateOverride = null): array
{
    $periodStatement = $pdo->prepare('SELECT * FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
    $periodStatement->execute([$payrollPeriodId]);
    $period = $periodStatement->fetch();
    if (!$period) {
        throw new RuntimeException('The selected payroll period could not be found.');
    }
    if ($period['wages_transaction_id'] !== null || $period['employer_contributions_transaction_id'] !== null) {
        return ['already_posted' => true, 'wages_transaction_id' => (int) $period['wages_transaction_id'], 'employer_contributions_transaction_id' => (int) $period['employer_contributions_transaction_id']];
    }

    $totalsStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(gross_pay), 0) AS gross, COALESCE(SUM(sss_employer + philhealth_employer + pagibig_employer), 0) AS employer_contrib
         FROM payroll_items WHERE payroll_period_id = ? AND deleted_at IS NULL"
    );
    $totalsStatement->execute([$payrollPeriodId]);
    $totals = $totalsStatement->fetch();
    $grossTotal = (float) $totals['gross'];
    $contribTotal = (float) $totals['employer_contrib'];
    $payDate = $payDateOverride ?? (string) $period['pay_date'];

    $insertTxn = $pdo->prepare(
        "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
         VALUES (?, 'Expense', 'Labor Expense', ?, ?, NULL, 'Bank Transfer', 'Recorded')"
    );

    $wagesTxnId = null;
    if ($grossTotal > 0) {
        $insertTxn->execute([$payDate, "Payroll wages — period {$period['period_start']} to {$period['period_end']}.", $grossTotal]);
        $wagesTxnId = (int) $pdo->lastInsertId();
    }

    $contribTxnId = null;
    if ($contribTotal > 0) {
        $insertTxn->execute([$payDate, "Employer SSS/PhilHealth/Pag-IBIG contributions — period {$period['period_start']} to {$period['period_end']}.", $contribTotal]);
        $contribTxnId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        "UPDATE payroll_periods SET status = 'Paid', paid_by = ?, paid_at = NOW(), wages_transaction_id = ?, employer_contributions_transaction_id = ? WHERE payroll_period_id = ?"
    )->execute([$paidBy, $wagesTxnId, $contribTxnId, $payrollPeriodId]);

    if (function_exists('audit_pms_action')) {
        try {
            audit_pms_action($pdo, 'UPDATE', 'payroll_periods', $payrollPeriodId);
        } catch (Throwable $exception) {
            error_log('Payroll audit log write failed: ' . $exception->getMessage());
        }
    }

    return ['already_posted' => false, 'wages_transaction_id' => $wagesTxnId, 'employer_contributions_transaction_id' => $contribTxnId];
}

/**
 * Allocates a Paid period's per-employee employer_cost into batch_costs (cost_category
 * 'Labor'), so it reaches the same per-batch cost figures (integrated_flock_financials(),
 * batch profitability, cost efficiency) as any other allocated cost. A 'Flock'-tagged
 * item allocates entirely to its assigned flock; an 'Overhead' item (farm management,
 * maintenance, feed & inventory, administration — shared across the whole farm) splits
 * proportionally across that period's active flocks by bird count, the same convention
 * already used for other shared overhead in this codebase. Skips flocks/periods with no
 * active birds rather than allocating to nothing. Safe to call only once per period —
 * callers should gate this the same way they gate payroll_post_to_ais().
 */
function payroll_allocate_batch_costs(PDO $pdo, int $payrollPeriodId): void
{
    $periodStatement = $pdo->prepare('SELECT * FROM payroll_periods WHERE payroll_period_id = ? AND deleted_at IS NULL');
    $periodStatement->execute([$payrollPeriodId]);
    $period = $periodStatement->fetch();
    if (!$period) {
        return;
    }
    $costDate = (string) $period['period_end'];

    $itemsStatement = $pdo->prepare(
        "SELECT pi.*, e.department, e.full_name FROM payroll_items pi
         JOIN employees e ON e.employee_id = pi.employee_id
         WHERE pi.payroll_period_id = ? AND pi.deleted_at IS NULL"
    );
    $itemsStatement->execute([$payrollPeriodId]);
    $items = $itemsStatement->fetchAll();
    if ($items === []) {
        return;
    }

    // Flocks active at any point during this payroll period, by bird-day weight —
    // approximated the same way the two-year dataset generator approximates it: a
    // flock's own production_records span stands in for its active window.
    $flockStatement = $pdo->prepare(
        "SELECT f.flock_id, f.initial_birds, MIN(p.record_date) AS first_d, MAX(p.record_date) AS last_d
         FROM flocks f JOIN production_records p ON p.flock_id = f.flock_id AND p.deleted_at IS NULL
         WHERE f.deleted_at IS NULL GROUP BY f.flock_id, f.initial_birds"
    );
    $flockStatement->execute();
    $activeFlocks = [];
    foreach ($flockStatement->fetchAll() as $row) {
        if ($row['first_d'] <= $period['period_end'] && $row['last_d'] >= $period['period_start']) {
            $activeFlocks[(int) $row['flock_id']] = max(1, (int) $row['initial_birds']);
        }
    }
    $totalBirds = array_sum($activeFlocks);

    $insertCost = $pdo->prepare(
        'INSERT INTO batch_costs (flock_id, cost_date, cost_category, description, amount, source_reference)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $employerCost = (float) $item['basic_pay'] + (float) $item['overtime_pay'] + (float) $item['allowances']
            + (float) $item['sss_employer'] + (float) $item['philhealth_employer'] + (float) $item['pagibig_employer'];
        if ($employerCost <= 0) {
            continue;
        }
        $reference = 'payroll_item:' . $item['payroll_item_id'];

        if ($item['allocation_type'] === 'Flock' && $item['allocation_flock_id'] !== null) {
            $insertCost->execute([
                (int) $item['allocation_flock_id'], $costDate, 'Labor',
                "Payroll allocation — {$item['full_name']} ({$item['department']}).", money2($employerCost), $reference,
            ]);
            continue;
        }

        if ($totalBirds <= 0) {
            continue;
        }
        foreach ($activeFlocks as $flockId => $birds) {
            $share = money2($employerCost * ($birds / $totalBirds));
            if ($share <= 0) {
                continue;
            }
            $insertCost->execute([
                $flockId, $costDate, 'Labor',
                "Payroll allocation (overhead share) — {$item['full_name']} ({$item['department']}).", $share, $reference,
            ]);
        }
    }
}

if (!function_exists('money2')) {
    function money2(float $value): float
    {
        return round($value, 2);
    }
}
