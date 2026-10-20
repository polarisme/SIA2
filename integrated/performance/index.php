<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_performance';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$pageTitle = 'Production & Financial Performance';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated performance database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$flockRows = [];
$operational = ['flocks' => 0, 'active_flocks' => 0, 'total_birds' => 0, 'initial_birds' => 0, 'mortality' => 0, 'eggs' => 0, 'avg_weight' => 0.0, 'activities_total' => 0, 'activities_completed' => 0, 'health_incidents' => 0];
$financial = ['income' => 0.0, 'expense' => 0.0, 'ar_outstanding' => 0.0, 'ap_outstanding' => 0.0];
$categoryTotals = array_fill_keys(INTEGRATED_COST_CATEGORIES, 0.0);
$trend = [];
$inventoryExposure = ['total_value' => 0.0, 'at_risk_value' => 0.0, 'items' => 0, 'at_risk_items' => 0];

if ($pdo instanceof PDO) {
    try {
        integrated_recalculate_all_batch_profitability($pdo);

        $flocks = $pdo->query('SELECT * FROM flocks WHERE deleted_at IS NULL ORDER BY status, batch_code')->fetchAll();
        $operational['flocks'] = count($flocks);
        $operational['active_flocks'] = count(array_filter($flocks, static fn ($f) => $f['status'] === 'Active'));
        $operational['initial_birds'] = (int) array_sum(array_column($flocks, 'initial_birds'));
        $operational['total_birds'] = (int) array_sum(array_column($flocks, 'current_birds'));

        foreach ($flocks as $flock) {
            $flockId = (int) $flock['flock_id'];
            $financials = integrated_flock_financials($pdo, $flockId);
            foreach (INTEGRATED_COST_CATEGORIES as $category) {
                $categoryTotals[$category] += $financials['cost_by_category'][$category];
            }

            $prodStatement = $pdo->prepare(
                'SELECT COALESCE(SUM(eggs_produced), 0) AS eggs, COALESCE(SUM(mortality_count), 0) AS mortality, COALESCE(AVG(average_weight), 0) AS avg_weight
                 FROM production_records WHERE flock_id = ? AND deleted_at IS NULL'
            );
            $prodStatement->execute([$flockId]);
            $prod = $prodStatement->fetch();
            $operational['eggs'] += (int) $prod['eggs'];
            $operational['mortality'] += (int) $prod['mortality'];

            $healthStatement = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE flock_id = ? AND deleted_at IS NULL AND record_type = 'Disease'");
            $healthStatement->execute([$flockId]);
            $healthIncidents = (int) $healthStatement->fetchColumn();
            $operational['health_incidents'] += $healthIncidents;

            $gp = $financials['revenue'] - $financials['total_cost'];
            $flockRows[] = [
                'flock' => $flock,
                'financials' => $financials,
                'eggs' => (int) $prod['eggs'],
                'mortality' => (int) $prod['mortality'],
                'gross_profit' => $gp,
            ];
        }

        $activitiesRow = $pdo->query("SELECT COUNT(*) AS total, SUM(status = 'Completed') AS completed FROM farm_activities WHERE deleted_at IS NULL")->fetch();
        $operational['activities_total'] = (int) $activitiesRow['total'];
        $operational['activities_completed'] = (int) $activitiesRow['completed'];

        $financeRow = $pdo->query(
            "SELECT transaction_type, COALESCE(SUM(amount), 0) AS total FROM financial_transactions
             WHERE status <> 'Cancelled' AND deleted_at IS NULL GROUP BY transaction_type"
        )->fetchAll();
        foreach ($financeRow as $row) {
            if ($row['transaction_type'] === 'Income') {
                $financial['income'] = (float) $row['total'];
            } else {
                $financial['expense'] = (float) $row['total'];
            }
        }

        $statusSql = ar_ap_status_sql();
        $financial['ar_outstanding'] = (float) $pdo->query("SELECT COALESCE(SUM(amount_due - amount_paid), 0) FROM accounts_receivable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetchColumn();
        $financial['ap_outstanding'] = (float) $pdo->query("SELECT COALESCE(SUM(amount_due - amount_paid), 0) FROM accounts_payable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL")->fetchColumn();

        // Monthly production output vs net income, for the trend chart.
        $monthlyProduction = $pdo->query(
            "SELECT DATE_FORMAT(record_date, '%Y-%m') AS ym, COALESCE(SUM(eggs_produced), 0) AS eggs
             FROM production_records WHERE deleted_at IS NULL GROUP BY ym ORDER BY ym"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $monthlyFinance = $pdo->query(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, transaction_type, COALESCE(SUM(amount), 0) AS total
             FROM financial_transactions WHERE status <> 'Cancelled' AND deleted_at IS NULL GROUP BY ym, transaction_type ORDER BY ym"
        )->fetchAll();
        $monthlyNet = [];
        foreach ($monthlyFinance as $row) {
            $monthlyNet[$row['ym']] = ($monthlyNet[$row['ym']] ?? 0.0) + ($row['transaction_type'] === 'Income' ? (float) $row['total'] : -(float) $row['total']);
        }
        $months = array_unique(array_merge(array_keys($monthlyProduction), array_keys($monthlyNet)));
        sort($months);
        foreach ($months as $month) {
            $trend[] = ['label' => $month, 'eggs' => (int) ($monthlyProduction[$month] ?? 0), 'net_income' => $monthlyNet[$month] ?? 0.0];
        }

        $inventoryRows = $pdo->query("SELECT quantity, unit_cost, status FROM poultry_inventory WHERE deleted_at IS NULL")->fetchAll();
        foreach ($inventoryRows as $row) {
            $value = (float) $row['quantity'] * (float) $row['unit_cost'];
            $inventoryExposure['total_value'] += $value;
            $inventoryExposure['items']++;
            if (in_array($row['status'], ['Low Stock', 'Out of Stock'], true)) {
                $inventoryExposure['at_risk_value'] += $value;
                $inventoryExposure['at_risk_items']++;
            }
        }
    } catch (PDOException $exception) {
        error_log('Integrated performance query failed: ' . $exception->getMessage());
        $databaseError = 'Some performance figures are unavailable because of a database error.';
    }
}

$netIncome = $financial['income'] - $financial['expense'];
$mortalityRate = $operational['initial_birds'] > 0 ? ($operational['mortality'] / $operational['initial_birds']) * 100 : null;
$activityCompletionRate = $operational['activities_total'] > 0 ? ($operational['activities_completed'] / $operational['activities_total']) * 100 : null;

// Comparison series across batches, for the "relationships" section.
$comparisonSampleSize = count($flockRows);
$eggsSeries = array_column($flockRows, 'eggs');
$revenueSeries = array_map(static fn ($r) => $r['financials']['revenue'], $flockRows);
$costSeries = array_map(static fn ($r) => $r['financials']['total_cost'], $flockRows);
$mortalitySeries = array_column($flockRows, 'mortality');
$birdSeries = array_map(static fn ($r) => (int) $r['flock']['current_birds'], $flockRows);
$profitSeries = array_column($flockRows, 'gross_profit');

$correlations = [
    'Production Output vs Revenue' => pearson_correlation($eggsSeries, $revenueSeries),
    'Production Output vs Cost' => pearson_correlation($eggsSeries, $costSeries),
    'Mortality vs Cost' => pearson_correlation($mortalitySeries, $costSeries),
    'Flock Size vs Revenue' => pearson_correlation($birdSeries, $revenueSeries),
    'Cost vs Profit' => pearson_correlation($costSeries, $profitSeries),
];

// Correlations and the comparison tables above/below always use the FULL $flockRows —
// only the "Batch Profitability" table's display is paginated.
$batchProfitabilityTotal = count($flockRows);
['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
$flockRowsPage = array_slice($flockRows, $offset, $perPage);

$pageBodyClass = 'page-performance';
$printReportTitle = 'YIELD — Production & Financial Performance Report';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading d-print-none">
    <div class="eyebrow">PRODUCTION AND FINANCIAL PERFORMANCE CORRELATION DASHBOARD</div>
    <h1>Production &amp; Financial Performance</h1>
    <p>Operational and financial indicators side by side, drawn live from PMS and AIS records.</p>
    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Export</button>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<h2 class="h5 mb-3">1. Overall Farm Performance</h2>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Flocks (Active / Total)</div><div class="stat-value"><?= $operational['active_flocks'] ?> / <?= $operational['flocks'] ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Current Birds</div><div class="stat-value"><?= number_format($operational['total_birds']) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Mortality Rate (of initial birds)</div><div class="stat-value"><?= $mortalityRate === null ? '—' : number_format($mortalityRate, 1) . '%' ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Activity Completion Rate</div><div class="stat-value"><?= $activityCompletionRate === null ? '—' : number_format($activityCompletionRate, 1) . '%' ?></div></div></div>
</div>

<h2 class="h5 mb-3">2. Production KPIs</h2>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Eggs Produced</div><div class="stat-value"><?= number_format($operational['eggs']) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Mortality</div><div class="stat-value"><?= number_format($operational['mortality']) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Farm Activities (Completed / Total)</div><div class="stat-value"><?= $operational['activities_completed'] ?> / <?= $operational['activities_total'] ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Disease Health Incidents</div><div class="stat-value <?= $operational['health_incidents'] > 0 ? 'text-danger' : '' ?>"><?= $operational['health_incidents'] ?></div></div></div>
</div>

<h2 class="h5 mb-3">3. Financial KPIs</h2>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Income</div><div class="stat-value text-success">₱<?= money($financial['income']) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Total Expenses</div><div class="stat-value text-danger">₱<?= money($financial['expense']) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Net Income</div><div class="stat-value <?= $netIncome >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($netIncome) ?></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">AR / AP Outstanding</div><div class="stat-value fs-5">₱<?= money($financial['ar_outstanding']) ?> / ₱<?= money($financial['ap_outstanding']) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">4. Cost Distribution by Category (whole farm)</h2></div>
            <div class="panel-body">
                <?php $grandTotalCost = array_sum($categoryTotals); ?>
                <?php if ($grandTotalCost <= 0): ?>
                    <p class="text-secondary mb-0">No cost data recorded yet.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Category</th><th class="text-end">Amount</th><th class="text-end">% of Total</th></tr></thead>
                        <tbody>
                            <?php foreach (INTEGRATED_COST_CATEGORIES as $category): ?>
                                <?php if ($categoryTotals[$category] <= 0) { continue; } ?>
                                <tr>
                                    <td><?= h($category) ?></td>
                                    <td class="text-end">₱<?= money($categoryTotals[$category]) ?></td>
                                    <td class="text-end"><?= number_format(($categoryTotals[$category] / $grandTotalCost) * 100, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">5. Inventory Financial Exposure</h2></div>
            <div class="panel-body">
                <div class="row g-3">
                    <div class="col-6"><div class="detail-label">Total inventory value</div><div class="detail-value fs-5">₱<?= money($inventoryExposure['total_value']) ?></div></div>
                    <div class="col-6"><div class="detail-label">Value in Low/Out of Stock items</div><div class="detail-value fs-5 text-danger">₱<?= money($inventoryExposure['at_risk_value']) ?></div></div>
                    <div class="col-6"><div class="detail-label">Total items</div><div class="detail-value"><?= $inventoryExposure['items'] ?></div></div>
                    <div class="col-6"><div class="detail-label">Items needing attention</div><div class="detail-value"><?= $inventoryExposure['at_risk_items'] ?></div></div>
                </div>
                <p class="small text-secondary mt-2 mb-0 d-print-none">"Total inventory value" = quantity &times; unit cost, summed across all active inventory items — the capital currently tied up in stock.</p>
            </div>
        </section>
    </div>
</div>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">6. Production vs Financial Trends</h2></div>
    <div class="panel-body">
        <?php if ($trend === []): ?>
            <p class="text-secondary mb-0">No production or financial history recorded yet.</p>
        <?php else: ?>
            <canvas id="trendChart" height="220" role="img" aria-label="Monthly egg production versus net income"></canvas>
        <?php endif; ?>
    </div>
</section>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">7. Batch Profitability</h2></div>
    <div>
        <table class="table table-sm mb-0">
            <thead><tr><th>Batch</th><th class="text-end">Birds</th><th class="text-end">Eggs</th><th class="text-end">Mortality</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
            <tbody>
                <?php foreach ($flockRowsPage as $row): ?>
                    <tr>
                        <td><?= h($row['flock']['batch_code']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['flock']['current_birds']) ?></td>
                        <td class="text-end"><?= number_format($row['eggs']) ?></td>
                        <td class="text-end"><?= number_format($row['mortality']) ?></td>
                        <td class="text-end">₱<?= money($row['financials']['revenue']) ?></td>
                        <td class="text-end">₱<?= money($row['financials']['total_cost']) ?></td>
                        <td class="text-end <?= $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($row['gross_profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination_controls($pageNum, $perPage, $batchProfitabilityTotal, $_GET, 'index.php'); ?>
</section>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Operational vs Financial Relationships</h2></div>
    <div class="panel-body">
        <?php if ($comparisonSampleSize < MIN_CORRELATION_SAMPLE): ?>
            <div class="alert alert-info" role="status">
                Statistical correlation is not shown because only <?= $comparisonSampleSize ?> batch(es) currently have data — a reliable correlation coefficient needs at least <?= MIN_CORRELATION_SAMPLE ?> data points. The table below shows the raw operational-vs-financial comparison per batch instead, without claiming a statistical relationship.
            </div>
            <div>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Batch</th><th class="text-end">Birds</th><th class="text-end">Eggs</th><th class="text-end">Mortality</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Profit</th></tr></thead>
                    <tbody>
                        <?php foreach ($flockRows as $row): ?>
                            <tr>
                                <td><?= h($row['flock']['batch_code']) ?></td>
                                <td class="text-end"><?= number_format((int) $row['flock']['current_birds']) ?></td>
                                <td class="text-end"><?= number_format($row['eggs']) ?></td>
                                <td class="text-end"><?= number_format($row['mortality']) ?></td>
                                <td class="text-end">₱<?= money($row['financials']['revenue']) ?></td>
                                <td class="text-end">₱<?= money($row['financials']['total_cost']) ?></td>
                                <td class="text-end">₱<?= money($row['gross_profit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="small text-secondary d-print-none">Calculated Pearson correlation coefficient (r), based on <?= $comparisonSampleSize ?> batches:</p>
            <table class="table table-sm mb-0">
                <thead><tr><th>Relationship</th><th class="text-end">Correlation (r)</th></tr></thead>
                <tbody>
                    <?php foreach ($correlations as $label => $r): ?>
                        <tr><td><?= h($label) ?></td><td class="text-end"><?= $r === null ? 'Not computable' : number_format($r, 3) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<?php if ($trend !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('trendChart'), {
    data: {
        labels: <?= json_encode(array_column($trend, 'label')) ?>,
        datasets: [
            { type: 'bar', label: 'Eggs Produced', data: <?= json_encode(array_column($trend, 'eggs')) ?>, backgroundColor: '#4fae72', yAxisID: 'y' },
            { type: 'line', label: 'Net Income (₱)', data: <?= json_encode(array_column($trend, 'net_income')) ?>, borderColor: '#0b4a28', backgroundColor: '#0b4a28', yAxisID: 'y1', tension: .3 }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Eggs Produced' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Net Income (₱)' } }
        },
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
