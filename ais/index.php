<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_dashboard';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';
$pageTitle = 'Financial Dashboard';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS dashboard database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

// --- Resolve the selected date range -------------------------------------------------
$range = (string) ($_GET['range'] ?? 'month');
if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
    $range = 'month';
}
$today = new DateTimeImmutable('today');
$customFrom = trim((string) ($_GET['date_from'] ?? ''));
$customTo = trim((string) ($_GET['date_to'] ?? ''));

switch ($range) {
    case 'today':
        $rangeFrom = $today;
        $rangeTo = $today;
        break;
    case 'week':
        $rangeFrom = $today->modify('monday this week');
        $rangeTo = $today;
        break;
    case 'year':
        $rangeFrom = $today->modify('first day of january this year');
        $rangeTo = $today;
        break;
    case 'custom':
        $rangeFrom = ais_valid_date($customFrom) ? new DateTimeImmutable($customFrom) : $today->modify('first day of this month');
        $rangeTo = ais_valid_date($customTo) ? new DateTimeImmutable($customTo) : $today;
        if ($rangeFrom > $rangeTo) {
            [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
        }
        break;
    case 'month':
    default:
        $rangeFrom = $today->modify('first day of this month');
        $rangeTo = $today;
        break;
}
$dateFrom = $rangeFrom->format('Y-m-d');
$dateTo = $rangeTo->format('Y-m-d');

// The immediately-preceding period of the same length, for period-over-period comparison.
// A partial "this month so far" range is compared against the same number of days at the
// start of the previous period, not a full previous month, so the comparison isn't skewed.
$rangeDays = (int) $rangeFrom->diff($rangeTo)->days + 1;
$prevRangeTo = $rangeFrom->modify('-1 day');
$prevRangeFrom = $prevRangeTo->modify('-' . ($rangeDays - 1) . ' days');
$prevDateFrom = $prevRangeFrom->format('Y-m-d');
$prevDateTo = $prevRangeTo->format('Y-m-d');

$totals = ['income' => 0.0, 'expense' => 0.0];
$prevTotals = ['income' => 0.0, 'expense' => 0.0];
$ar = ['outstanding' => 0.0, 'overdue' => 0.0, 'count' => 0, 'overdue_count' => 0];
$ap = ['outstanding' => 0.0, 'overdue' => 0.0, 'count' => 0, 'overdue_count' => 0];
$cashPosition = 0.0;
$trend = [];
$topIncomeCategories = [];
$topExpenseCategories = [];
$arAging = [];
$apAging = [];
$recentTransactions = [];
$overdueReceivables = [];
$overduePayables = [];

$agingBuckets = ['Not Yet Due' => 0.0, '1-30 Days' => 0.0, '31-60 Days' => 0.0, '61-90 Days' => 0.0, '90+ Days' => 0.0];

if ($pdo instanceof PDO) {
    try {
        // Income / expense totals for the selected range. Cancelled transactions are voided
        // and excluded from every financial total in AIS (dashboard, list totals, reports).
        $statement = $pdo->prepare(
            "SELECT transaction_type, COALESCE(SUM(amount), 0) AS total
             FROM financial_transactions
             WHERE status <> 'Cancelled' AND deleted_at IS NULL AND transaction_date BETWEEN ? AND ?
             GROUP BY transaction_type"
        );
        $statement->execute([$dateFrom, $dateTo]);
        foreach ($statement->fetchAll() as $row) {
            if ($row['transaction_type'] === 'Income') {
                $totals['income'] = (float) $row['total'];
            } elseif ($row['transaction_type'] === 'Expense') {
                $totals['expense'] = (float) $row['total'];
            }
        }

        $statement->execute([$prevDateFrom, $prevDateTo]);
        foreach ($statement->fetchAll() as $row) {
            if ($row['transaction_type'] === 'Income') {
                $prevTotals['income'] = (float) $row['total'];
            } elseif ($row['transaction_type'] === 'Expense') {
                $prevTotals['expense'] = (float) $row['total'];
            }
        }

        // Cash position: same formula as the "Statement of Financial Position (Simplified)"
        // report (ais/reports) — all-time net of non-cancelled transactions up to today. Not
        // range-bound, since cash on hand is a point-in-time balance, not a period total.
        $cashPosition = (float) $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE -amount END), 0)
             FROM financial_transactions WHERE status <> 'Cancelled' AND deleted_at IS NULL AND transaction_date <= CURDATE()"
        )->fetchColumn();

        // Accounts receivable / payable are point-in-time obligations, not range-bound.
        $statusSql = ar_ap_status_sql();
        $arRow = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN ($statusSql) = 'Overdue' THEN amount_due - amount_paid ELSE 0 END), 0) AS overdue,
                SUM(($statusSql) = 'Overdue') AS overdue_count
            FROM accounts_receivable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetch();
        $ar = ['outstanding' => (float) $arRow['outstanding'], 'overdue' => (float) $arRow['overdue'], 'count' => (int) $arRow['n'], 'overdue_count' => (int) $arRow['overdue_count']];

        $apRow = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN ($statusSql) = 'Overdue' THEN amount_due - amount_paid ELSE 0 END), 0) AS overdue,
                SUM(($statusSql) = 'Overdue') AS overdue_count
            FROM accounts_payable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetch();
        $ap = ['outstanding' => (float) $apRow['outstanding'], 'overdue' => (float) $apRow['overdue'], 'count' => (int) $apRow['n'], 'overdue_count' => (int) $apRow['overdue_count']];

        // Monthly income-vs-expense trend across the selected range (at least 1 month wide).
        $trendStatement = $pdo->prepare(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, transaction_type, COALESCE(SUM(amount), 0) AS total
             FROM financial_transactions
             WHERE status <> 'Cancelled' AND deleted_at IS NULL AND transaction_date BETWEEN ? AND ?
             GROUP BY ym, transaction_type
             ORDER BY ym"
        );
        $trendStatement->execute([$dateFrom, $dateTo]);
        $trendMap = [];
        foreach ($trendStatement->fetchAll() as $row) {
            $trendMap[$row['ym']][$row['transaction_type']] = (float) $row['total'];
        }
        ksort($trendMap);
        foreach ($trendMap as $ym => $values) {
            $trend[] = ['label' => $ym, 'income' => $values['Income'] ?? 0.0, 'expense' => $values['Expense'] ?? 0.0];
        }

        $categoryStatement = $pdo->prepare(
            "SELECT category, COALESCE(SUM(amount), 0) AS total
             FROM financial_transactions
             WHERE status <> 'Cancelled' AND deleted_at IS NULL AND transaction_type = ? AND transaction_date BETWEEN ? AND ?
             GROUP BY category ORDER BY total DESC LIMIT 5"
        );
        $categoryStatement->execute(['Income', $dateFrom, $dateTo]);
        $topIncomeCategories = $categoryStatement->fetchAll();
        $categoryStatement->execute(['Expense', $dateFrom, $dateTo]);
        $topExpenseCategories = $categoryStatement->fetchAll();

        // Receivable / payable aging, by days past due — not shown elsewhere in AIS reports,
        // which only expose the Unpaid/Partially Paid/Paid/Overdue status, not a day-bucketed view.
        $agingStatement = $pdo->prepare(
            "SELECT
                CASE
                    WHEN due_date >= CURDATE() THEN 'Not Yet Due'
                    WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN '1-30 Days'
                    WHEN DATEDIFF(CURDATE(), due_date) <= 60 THEN '31-60 Days'
                    WHEN DATEDIFF(CURDATE(), due_date) <= 90 THEN '61-90 Days'
                    ELSE '90+ Days'
                END AS bucket,
                COALESCE(SUM(amount_due - amount_paid), 0) AS total
             FROM accounts_receivable WHERE deleted_at IS NULL AND (amount_due - amount_paid) > 0
             GROUP BY bucket"
        );
        $agingStatement->execute();
        $arAging = $agingBuckets;
        foreach ($agingStatement->fetchAll() as $row) {
            $arAging[$row['bucket']] = (float) $row['total'];
        }

        $agingStatement = $pdo->prepare(str_replace('accounts_receivable', 'accounts_payable', $agingStatement->queryString));
        $agingStatement->execute();
        $apAging = $agingBuckets;
        foreach ($agingStatement->fetchAll() as $row) {
            $apAging[$row['bucket']] = (float) $row['total'];
        }

        $recentTransactions = $pdo->query(
            "SELECT transaction_id, transaction_date, transaction_type, category, description, amount, status
             FROM financial_transactions WHERE deleted_at IS NULL ORDER BY transaction_date DESC, transaction_id DESC LIMIT 8"
        )->fetchAll();

        $overdueReceivables = $pdo->query(
            "SELECT ar_id, customer_name, invoice_number, due_date, (amount_due - amount_paid) AS balance
             FROM accounts_receivable WHERE deleted_at IS NULL AND ($statusSql) = 'Overdue' ORDER BY due_date ASC LIMIT 6"
        )->fetchAll();

        $overduePayables = $pdo->query(
            "SELECT ap_id, supplier_name, invoice_number, due_date, (amount_due - amount_paid) AS balance
             FROM accounts_payable WHERE deleted_at IS NULL AND ($statusSql) = 'Overdue' ORDER BY due_date ASC LIMIT 6"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('AIS dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Some dashboard figures are unavailable because of a database error.';
    }
}
$netIncome = $totals['income'] - $totals['expense'];
$prevNetIncome = $prevTotals['income'] - $prevTotals['expense'];
$incomeChangePercent = $prevTotals['income'] > 0 ? (($totals['income'] - $prevTotals['income']) / $prevTotals['income']) * 100 : null;
$expenseChangePercent = $prevTotals['expense'] > 0 ? (($totals['expense'] - $prevTotals['expense']) / $prevTotals['expense']) * 100 : null;
$profitMargin = $totals['income'] > 0 ? ($netIncome / $totals['income']) * 100 : null;

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">FINANCIAL OVERVIEW</div>
    <h1>Financial Dashboard</h1>
    <p>Income, expenses, cash position, and outstanding balances at a glance.</p>
</div>

<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-3">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="range">Reporting period</label>
                <select class="form-select" id="range" name="range" onchange="document.getElementById('customRangeFields').classList.toggle('d-none', this.value !== 'custom')">
                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $range === 'year' ? 'selected' : '' ?>>This Year</option>
                    <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                </select>
            </div>
            <div class="col-md-6" id="customRangeFields"<?= $range === 'custom' ? '' : ' class="col-md-6 d-none"' ?>>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="date_from">From</label>
                        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($customFrom) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="date_to">To</label>
                        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($customTo) ?>">
                    </div>
                </div>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
        </form>
        <p class="small text-secondary mt-2 mb-0">Income/expense figures cover <?= h($dateFrom) ?> to <?= h($dateTo) ?> (compared against <?= h($prevDateFrom) ?> to <?= h($prevDateTo) ?>). Cash position, and receivable/payable totals, reflect the current point-in-time balance regardless of the period selected above.</p>
    </div>
</section>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Total Income</div>
            <div class="stat-value text-success">₱<?= money($totals['income']) ?></div>
            <div class="small mt-1 <?= $incomeChangePercent === null ? 'text-secondary' : ($incomeChangePercent >= 0 ? 'text-success' : 'text-danger') ?>">
                <?= $incomeChangePercent === null ? 'No prior period to compare' : (($incomeChangePercent >= 0 ? '+' : '') . number_format($incomeChangePercent, 1) . '% vs previous period') ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Total Expenses</div>
            <div class="stat-value text-danger">₱<?= money($totals['expense']) ?></div>
            <div class="small mt-1 <?= $expenseChangePercent === null ? 'text-secondary' : ($expenseChangePercent <= 0 ? 'text-success' : 'text-danger') ?>">
                <?= $expenseChangePercent === null ? 'No prior period to compare' : (($expenseChangePercent >= 0 ? '+' : '') . number_format($expenseChangePercent, 1) . '% vs previous period') ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Net Income (margin)</div>
            <div class="stat-value <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($netIncome) ?></div>
            <div class="small text-secondary mt-1"><?= $profitMargin === null ? 'No income this period' : number_format($profitMargin, 1) . '% margin' ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Cash Position (all time)</div>
            <div class="stat-value <?= $cashPosition >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($cashPosition) ?></div>
            <div class="small text-secondary mt-1">Cash-basis: all recorded income minus expenses to date</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/ais/receivables/index.php')) ?>">
            <div class="stat-label">Accounts Receivable (outstanding)</div>
            <div class="stat-value">₱<?= money($ar['outstanding']) ?></div>
            <div class="small text-secondary mt-1"><?= (int) $ar['count'] ?> open invoice(s)<?php if ($ar['overdue'] > 0): ?> &middot; <span class="text-danger">₱<?= money($ar['overdue']) ?> overdue</span><?php endif; ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/ais/payables/index.php')) ?>">
            <div class="stat-label">Accounts Payable (outstanding)</div>
            <div class="stat-value">₱<?= money($ap['outstanding']) ?></div>
            <div class="small text-secondary mt-1"><?= (int) $ap['count'] ?> open invoice(s)<?php if ($ap['overdue'] > 0): ?> &middot; <span class="text-danger">₱<?= money($ap['overdue']) ?> overdue</span><?php endif; ?></div>
        </a>
    </div>
</div>

<?php if ($ar['overdue_count'] > 0 || $ap['overdue_count'] > 0): ?>
<div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" role="alert">
    <span><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <strong><?= $ar['overdue_count'] ?></strong> customer invoice(s) overdue (₱<?= money($ar['overdue']) ?>) and <strong><?= $ap['overdue_count'] ?></strong> supplier bill(s) overdue (₱<?= money($ap['overdue']) ?>).</span>
    <span class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-danger" href="<?= h(app_url('/ais/receivables/index.php')) ?>">Follow Up Receivables</a>
        <a class="btn btn-sm btn-outline-danger" href="<?= h(app_url('/ais/payables/index.php')) ?>">Review Payables</a>
    </span>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Income vs Expenses</h2></div>
            <div class="panel-body">
                <?php if ($trend === []): ?>
                    <p class="text-secondary mb-0">No transactions recorded in this range.</p>
                <?php else: ?>
                    <canvas id="trendChart" height="220" role="img" aria-label="Income versus expenses by month"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Expense Breakdown (this period)</h2></div>
            <div class="panel-body">
                <?php if ($topExpenseCategories === []): ?>
                    <p class="text-secondary mb-0">No expenses recorded in this range.</p>
                <?php else: ?>
                    <canvas id="expenseDonut" height="160" role="img" aria-label="Top expense categories this period"></canvas>
                    <ul class="list-unstyled mb-0 mt-2">
                        <?php foreach ($topExpenseCategories as $row): ?>
                            <li class="d-flex justify-content-between border-bottom py-1"><span><?= h($row['category']) ?></span><strong class="text-danger">₱<?= money($row['total']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Accounts Receivable Aging</h2></div>
            <div class="panel-body">
                <?php if ($ar['count'] === 0): ?>
                    <p class="text-secondary mb-0">No outstanding receivables.</p>
                <?php else: ?>
                    <canvas id="arAgingChart" height="160" role="img" aria-label="Outstanding receivables by days past due"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Accounts Payable Aging</h2></div>
            <div class="panel-body">
                <?php if ($ap['count'] === 0): ?>
                    <p class="text-secondary mb-0">No outstanding payables.</p>
                <?php else: ?>
                    <canvas id="apAgingChart" height="160" role="img" aria-label="Outstanding payables by days past due"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Recent Transactions</h2>
                <a class="small" href="<?= h(app_url('/ais/transactions/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentTransactions === []): ?>
                            <tr><td class="text-center text-secondary py-3">No transactions recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentTransactions as $txn): ?>
                                <tr>
                                    <td>
                                        <a href="<?= h(app_url('/ais/transactions/index.php')) ?>?action=view&amp;id=<?= (int) $txn['transaction_id'] ?>"><?= h($txn['category']) ?></a>
                                        <span class="badge text-bg-<?= ais_transaction_status_badge($txn['status']) ?>"><?= h($txn['status']) ?></span>
                                        <div class="small text-secondary"><?= h((string) $txn['transaction_date']) ?></div>
                                    </td>
                                    <td class="text-end <?= $txn['transaction_type'] === 'Income' ? 'text-success' : 'text-danger' ?>">
                                        <?= $txn['transaction_type'] === 'Income' ? '+' : '-' ?>₱<?= money($txn['amount']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Overdue Receivables</h2>
                <a class="small" href="<?= h(app_url('/ais/receivables/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($overdueReceivables === []): ?>
                            <tr><td class="text-center text-secondary py-3">No overdue receivables.</td></tr>
                        <?php else: ?>
                            <?php foreach ($overdueReceivables as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?= h(app_url('/ais/receivables/index.php')) ?>?action=view&amp;id=<?= (int) $row['ar_id'] ?>"><?= h($row['customer_name']) ?></a>
                                        <div class="small text-secondary">Due <?= h((string) $row['due_date']) ?></div>
                                    </td>
                                    <td class="text-end text-danger">₱<?= money($row['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-3">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Overdue Payables</h2>
                <a class="small" href="<?= h(app_url('/ais/payables/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($overduePayables === []): ?>
                            <tr><td class="text-center text-secondary py-3">No overdue payables.</td></tr>
                        <?php else: ?>
                            <?php foreach ($overduePayables as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?= h(app_url('/ais/payables/index.php')) ?>?action=view&amp;id=<?= (int) $row['ap_id'] ?>"><?= h($row['supplier_name']) ?></a>
                                        <div class="small text-secondary">Due <?= h((string) $row['due_date']) ?></div>
                                    </td>
                                    <td class="text-end text-danger">₱<?= money($row['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Quick Actions</h2></div>
    <div class="panel-body d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= h(app_url('/ais/transactions/index.php')) ?>?action=add&amp;type=Income">Record Income</a>
        <a class="btn btn-outline-primary" href="<?= h(app_url('/ais/transactions/index.php')) ?>?action=add&amp;type=Expense">Record Expense</a>
        <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/receivables/index.php')) ?>?action=add">Add Receivable</a>
        <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/payables/index.php')) ?>?action=add">Add Payable</a>
        <a class="btn btn-outline-secondary" href="<?= h(app_url('/ais/reports/index.php')) ?>">Generate Report</a>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
<?php if ($trend !== []): ?>
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($trend, 'label')) ?>,
        datasets: [
            { label: 'Income', data: <?= json_encode(array_column($trend, 'income')) ?>, backgroundColor: '#276749' },
            { label: 'Expense', data: <?= json_encode(array_column($trend, 'expense')) ?>, backgroundColor: '#b3454a' }
        ]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true } },
        plugins: { legend: { position: 'bottom' } }
    }
});
<?php endif; ?>
<?php if ($topExpenseCategories !== []): ?>
new Chart(document.getElementById('expenseDonut'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($topExpenseCategories, 'category')) ?>,
        datasets: [{ data: <?= json_encode(array_map('floatval', array_column($topExpenseCategories, 'total'))) ?>, backgroundColor: ['#0a2d8f', '#0256c4', '#07b5fb', '#b3454a', '#6b7280'] }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});
<?php endif; ?>
<?php if ($ar['count'] > 0): ?>
new Chart(document.getElementById('arAgingChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($arAging)) ?>,
        datasets: [{ label: 'Outstanding (₱)', data: <?= json_encode(array_values($arAging)) ?>, backgroundColor: '#0256c4' }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
});
<?php endif; ?>
<?php if ($ap['count'] > 0): ?>
new Chart(document.getElementById('apAgingChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($apAging)) ?>,
        datasets: [{ label: 'Outstanding (₱)', data: <?= json_encode(array_values($apAging)) ?>, backgroundColor: '#b3454a' }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/../shared/includes/footer.php'; ?>
