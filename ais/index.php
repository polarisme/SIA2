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

$totals = ['income' => 0.0, 'expense' => 0.0];
$ar = ['outstanding' => 0.0, 'overdue' => 0.0, 'count' => 0];
$ap = ['outstanding' => 0.0, 'overdue' => 0.0, 'count' => 0];
$trend = [];
$topIncomeCategories = [];
$topExpenseCategories = [];

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

        // Accounts receivable / payable are point-in-time obligations, not range-bound.
        $statusSql = ar_ap_status_sql();
        $arRow = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN ($statusSql) = 'Overdue' THEN amount_due - amount_paid ELSE 0 END), 0) AS overdue
            FROM accounts_receivable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetch();
        $ar = ['outstanding' => (float) $arRow['outstanding'], 'overdue' => (float) $arRow['overdue'], 'count' => (int) $arRow['n']];

        $apRow = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN ($statusSql) = 'Overdue' THEN amount_due - amount_paid ELSE 0 END), 0) AS overdue
            FROM accounts_payable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetch();
        $ap = ['outstanding' => (float) $apRow['outstanding'], 'overdue' => (float) $apRow['overdue'], 'count' => (int) $apRow['n']];

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
    } catch (PDOException $exception) {
        error_log('AIS dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Some dashboard figures are unavailable because of a database error.';
    }
}
$netIncome = $totals['income'] - $totals['expense'];

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">FINANCIAL OVERVIEW</div>
    <h1>Financial Dashboard</h1>
    <p>Income, expenses, and outstanding balances at a glance.</p>
</div>

<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="range">Date range</label>
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
        <p class="small text-secondary mt-2 mb-0">Showing income and expenses from <?= h($dateFrom) ?> to <?= h($dateTo) ?>. Receivable and payable totals reflect all currently outstanding balances.</p>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Total Income</div>
            <div class="stat-value text-success">₱<?= money($totals['income']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Total Expenses</div>
            <div class="stat-value text-danger">₱<?= money($totals['expense']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-label">Net Income</div>
            <div class="stat-value <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($netIncome) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-6">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/ais/receivables/index.php')) ?>">
            <div class="stat-label">Accounts Receivable (outstanding)</div>
            <div class="stat-value">₱<?= money($ar['outstanding']) ?></div>
            <div class="small text-secondary mt-1"><?= (int) $ar['count'] ?> open invoice(s)<?php if ($ar['overdue'] > 0): ?> &middot; <span class="text-danger">₱<?= money($ar['overdue']) ?> overdue</span><?php endif; ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-6">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/ais/payables/index.php')) ?>">
            <div class="stat-label">Accounts Payable (outstanding)</div>
            <div class="stat-value">₱<?= money($ap['outstanding']) ?></div>
            <div class="small text-secondary mt-1"><?= (int) $ap['count'] ?> open invoice(s)<?php if ($ap['overdue'] > 0): ?> &middot; <span class="text-danger">₱<?= money($ap['overdue']) ?> overdue</span><?php endif; ?></div>
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
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
            <div class="panel-heading"><h2 class="h5 mb-0">Top Categories</h2></div>
            <div class="panel-body">
                <div class="detail-label">Income</div>
                <?php if ($topIncomeCategories === []): ?>
                    <p class="text-secondary small">No income recorded in this range.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($topIncomeCategories as $row): ?>
                            <li class="d-flex justify-content-between border-bottom py-1"><span><?= h($row['category']) ?></span><strong class="text-success">₱<?= money($row['total']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="detail-label">Expense</div>
                <?php if ($topExpenseCategories === []): ?>
                    <p class="text-secondary small mb-0">No expenses recorded in this range.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($topExpenseCategories as $row): ?>
                            <li class="d-flex justify-content-between border-bottom py-1"><span><?= h($row['category']) ?></span><strong class="text-danger">₱<?= money($row['total']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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

<?php if ($trend !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
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
</script>
<?php endif; ?>
<?php require __DIR__ . '/../shared/includes/footer.php'; ?>
