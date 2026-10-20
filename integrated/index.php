<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_dashboard';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$pageTitle = 'Integrated Dashboard';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated dashboard database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$flocks = [];
$totals = ['income' => 0.0, 'expense' => 0.0];
$attentionCount = 0;
$flockRows = [];
$aboveAverageFlags = []; // flock_id => [category, value, average]
$replenishmentQueue = [];
$lowStockRows = [];
$recentScenarios = [];
$eggTrendInsight = null;
$mortalityAlert = null;
$overdueArCount = 0;
$overdueArAmount = 0.0;

if ($pdo instanceof PDO) {
    try {
        integrated_recalculate_all_batch_profitability($pdo);

        $flocks = $pdo->query('SELECT flock_id, batch_code, breed, status, current_birds, initial_birds FROM flocks WHERE deleted_at IS NULL ORDER BY status, batch_code')->fetchAll();

        $prodRow = $pdo->query(
            "SELECT COALESCE(SUM(eggs_produced), 0) AS production, COALESCE(SUM(mortality_count), 0) AS mortality
             FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id
             WHERE p.deleted_at IS NULL AND f.deleted_at IS NULL"
        )->fetch();

        $profitRow = $pdo->query(
            "SELECT COALESCE(SUM(bp.total_cost), 0) AS total_cost, COALESCE(SUM(bp.total_revenue), 0) AS total_revenue
             FROM batch_profitability bp JOIN flocks f ON f.flock_id = bp.flock_id WHERE f.deleted_at IS NULL"
        )->fetch();
        $totals['cost'] = (float) $profitRow['total_cost'];
        $totals['income'] = (float) $profitRow['total_revenue'];

        $attentionCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM poultry_inventory WHERE deleted_at IS NULL AND status IN ('Low Stock', 'Out of Stock')"
        )->fetchColumn();

        // Per-batch financials + the same "cost per bird by category" comparison the Cost
        // Efficiency page uses, so "below-target" batches here mean exactly what that page
        // would flag — not a newly-invented threshold.
        $categoryValuesByFlock = [];
        $eggsStatement = $pdo->prepare('SELECT COALESCE(SUM(eggs_produced), 0), COALESCE(SUM(good_eggs),0) FROM production_records WHERE flock_id = ? AND deleted_at IS NULL');
        foreach ($flocks as $flock) {
            $flockId = (int) $flock['flock_id'];
            $financials = integrated_flock_financials($pdo, $flockId);
            $eggsStatement->execute([$flockId]);
            [$eggs, $goodEggs] = $eggsStatement->fetch(PDO::FETCH_NUM);
            $birds = max(1, (int) $flock['current_birds']);
            $costPerBirdByCategory = [];
            foreach (INTEGRATED_COST_CATEGORIES as $category) {
                $categoryCost = $financials['cost_by_category'][$category];
                if ($categoryCost > 0 && (int) $flock['current_birds'] > 0) {
                    $perBird = $categoryCost / $birds;
                    $costPerBirdByCategory[$category] = $perBird;
                    $categoryValuesByFlock[$category][$flockId] = $perBird;
                }
            }
            $flockRows[] = [
                'flock' => $flock,
                'financials' => $financials,
                'eggs' => (int) $eggs,
                'good_eggs' => (int) $goodEggs,
                'gross_profit' => $financials['revenue'] - $financials['total_cost'],
                'cost_per_bird_total' => (int) $flock['current_birds'] > 0 ? $financials['total_cost'] / $birds : null,
                'cost_per_bird_by_category' => $costPerBirdByCategory,
            ];
        }
        foreach ($flockRows as $row) {
            $flockId = (int) $row['flock']['flock_id'];
            foreach ($row['cost_per_bird_by_category'] as $category => $value) {
                $others = $categoryValuesByFlock[$category] ?? [];
                unset($others[$flockId]);
                $sampleSize = count($others);
                if ($sampleSize < 2) {
                    continue;
                }
                $average = array_sum($others) / $sampleSize;
                if ($value > $average * 1.5) {
                    $aboveAverageFlags[] = ['batch_code' => $row['flock']['batch_code'], 'category' => $category, 'value' => $value, 'average' => $average];
                }
            }
        }

        // Replenishment queue: the most urgent still-open recommendations, same
        // urgency ordering as the Inventory Replenishment page.
        $replenishmentQueue = $pdo->query(
            "SELECT ir.*, pi.item_name, pi.item_code, pi.quantity AS live_quantity
             FROM inventory_replenishment ir JOIN poultry_inventory pi ON pi.item_id = ir.item_id
             WHERE ir.deleted_at IS NULL AND ir.status IN ('Pending', 'Approved')
             ORDER BY ir.created_at DESC LIMIT 40"
        )->fetchAll();
        usort($replenishmentQueue, static fn ($a, $b) => replenishment_urgency_rank((string) $a['recommendation_status']) <=> replenishment_urgency_rank((string) $b['recommendation_status']));
        $replenishmentQueue = array_slice($replenishmentQueue, 0, 6);

        $lowStockRows = $pdo->query(
            "SELECT item_code, item_name, quantity, reorder_level, unit FROM poultry_inventory
             WHERE deleted_at IS NULL AND status IN ('Low Stock', 'Out of Stock') ORDER BY quantity ASC LIMIT 8"
        )->fetchAll();

        $recentScenarios = $pdo->query(
            'SELECT * FROM scenarios WHERE deleted_at IS NULL ORDER BY created_at DESC, scenario_id DESC LIMIT 4'
        )->fetchAll();

        // Egg production trend insight: last 4 weeks vs the 4 weeks before that, farm-wide.
        $eggWindow = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN record_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK) THEN eggs_produced ELSE 0 END), 0) AS recent,
                COALESCE(SUM(CASE WHEN record_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) AND record_date < DATE_SUB(CURDATE(), INTERVAL 4 WEEK) THEN eggs_produced ELSE 0 END), 0) AS prior
             FROM production_records WHERE deleted_at IS NULL"
        )->fetch();
        if ((float) $eggWindow['prior'] > 0) {
            $change = (((float) $eggWindow['recent'] - (float) $eggWindow['prior']) / (float) $eggWindow['prior']) * 100;
            $eggTrendInsight = ['change' => $change, 'recent' => (int) $eggWindow['recent'], 'prior' => (int) $eggWindow['prior']];
        }

        $highMortality = pms_health_alerts($pdo)['high_mortality'];
        $mortalityAlert = $highMortality[0] ?? null;

        $statusSql = ar_ap_status_sql();
        $overdueRow = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS total FROM accounts_receivable WHERE deleted_at IS NULL AND ($statusSql) = 'Overdue'")->fetch();
        $overdueArCount = (int) $overdueRow['n'];
        $overdueArAmount = (float) $overdueRow['total'];
    } catch (PDOException $exception) {
        error_log('Integrated dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Some dashboard figures are unavailable because of a database error.';
    }
}

$activeFlocks = array_filter($flocks, static fn (array $f): bool => $f['status'] === 'Active');
$totalBirds = array_sum(array_column($activeFlocks, 'current_birds'));
$grossProfit = $totals['income'] - $totals['cost'];
$profitMargin = $totals['income'] > 0 ? ($grossProfit / $totals['income']) * 100 : null;

// Egg cost per dozen, farm-wide: total allocated+AIS cost attributed to egg-producing
// flocks, divided by total good eggs produced (in dozens). Only meaningful when there is
// at least one flock with recorded egg production and cost data.
$eggProducingRows = array_filter($flockRows, static fn ($r) => $r['good_eggs'] > 0);
$totalEggCost = array_sum(array_map(static fn ($r) => $r['financials']['total_cost'], $eggProducingRows));
$totalGoodEggs = array_sum(array_column($eggProducingRows, 'good_eggs'));
$costPerDozenEggs = $totalGoodEggs > 0 ? $totalEggCost / ($totalGoodEggs / 12) : null;

// Completed-batch profitability ranking (Sold/Completed/Terminated only — active batches
// are still accumulating cost and aren't comparable to a finished batch's final numbers).
$completedBatchRows = array_values(array_filter($flockRows, static fn ($r) => $r['flock']['status'] !== 'Active' && $r['financials']['has_data']));
usort($completedBatchRows, static fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
$topBatches = array_slice($completedBatchRows, 0, 3);
$bottomBatches = array_slice(array_reverse($completedBatchRows), 0, 3);

// Recommendations: short, data-driven, each tied to a real condition above.
$recommendations = [];
if ($attentionCount > 0) {
    $recommendations[] = ['text' => "Replenish {$attentionCount} inventory item(s) currently Low or Out of Stock before they interrupt operations.", 'url' => app_url('/integrated/inventory-replenishment/index.php'), 'icon' => 'bi-box-seam'];
}
if ($overdueArCount > 0) {
    $recommendations[] = ['text' => "Follow up {$overdueArCount} overdue customer invoice(s) totaling ₱" . money($overdueArAmount) . '.', 'url' => app_url('/ais/receivables/index.php'), 'icon' => 'bi-cash-stack'];
}
if ($mortalityAlert !== null) {
    $recommendations[] = ['text' => "Investigate batch {$mortalityAlert['batch_code']}: " . number_format((float) $mortalityAlert['mortality_rate'], 2) . '% mortality in the last 7 days.', 'url' => app_url('/pms/health/index.php'), 'icon' => 'bi-heart-pulse'];
}
if ($aboveAverageFlags !== []) {
    $worst = $aboveAverageFlags[0];
    $recommendations[] = ['text' => "Batch {$worst['batch_code']} has a {$worst['category']} cost per bird (₱" . money($worst['value']) . ') more than 1.5x the farm average (₱' . money($worst['average']) . ').', 'url' => app_url('/integrated/cost-efficiency/index.php'), 'icon' => 'bi-graph-down-arrow'];
}
if ($eggTrendInsight !== null && $eggTrendInsight['change'] < -5) {
    $recommendations[] = ['text' => 'Egg production is down ' . number_format(abs($eggTrendInsight['change']), 1) . '% over the last 4 weeks compared with the 4 weeks before that.', 'url' => app_url('/pms/production/index.php'), 'icon' => 'bi-egg-fried'];
}

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">YIELD INTEGRATED SYSTEM &middot; DECISION SUPPORT</div>
    <h1>Integrated Dashboard</h1>
    <p>Poultry operations and accounting information brought together for management decision-making.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Total Revenue (all time, actual)</div>
            <div class="stat-value text-success">₱<?= money($totals['income']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Total Operating Cost (all time, actual)</div>
            <div class="stat-value text-danger">₱<?= money($totals['cost']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Net Income / Margin (actual)</div>
            <div class="stat-value <?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($grossProfit) ?></div>
            <div class="small text-secondary mt-1"><?= $profitMargin === null ? '—' : number_format($profitMargin, 1) . '% margin' ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Egg Cost per Dozen (actual)</div>
            <div class="stat-value"><?= $costPerDozenEggs === null ? '—' : '₱' . money($costPerDozenEggs) ?></div>
            <div class="small text-secondary mt-1">Egg-producing flocks only</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Active Flocks</div>
            <div class="stat-value"><?= count($activeFlocks) ?></div>
            <div class="small text-secondary mt-1"><?= number_format((int) $totalBirds) ?> current birds</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/integrated/inventory-replenishment/index.php')) ?>">
            <div class="stat-label">Inventory Items Requiring Attention</div>
            <div class="stat-value <?= $attentionCount > 0 ? 'text-danger' : '' ?>"><?= $attentionCount ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/integrated/cost-efficiency/index.php')) ?>">
            <div class="stat-label">Batches Above-Average Cost</div>
            <div class="stat-value <?= count($aboveAverageFlags) > 0 ? 'text-danger' : '' ?>"><?= count(array_unique(array_column($aboveAverageFlags, 'batch_code'))) ?></div>
            <div class="small text-secondary mt-1">&gt;1.5&times; farm average, any category</div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Production Output (eggs, all time)</div>
            <div class="stat-value"><?= number_format((int) ($prodRow['production'] ?? 0)) ?></div>
            <div class="small text-secondary mt-1"><?= number_format((int) ($prodRow['mortality'] ?? 0)) ?> cumulative mortality</div>
        </div>
    </div>
</div>

<?php if ($recommendations !== []): ?>
<section class="content-panel mb-3">
    <div class="panel-heading"><h2 class="h5 mb-0"><i class="bi bi-lightbulb-fill" aria-hidden="true"></i> Recommendations</h2></div>
    <div class="panel-body">
        <ul class="list-unstyled mb-0">
            <?php foreach ($recommendations as $rec): ?>
                <li class="d-flex justify-content-between align-items-start gap-3 border-bottom py-2">
                    <span><i class="bi <?= h($rec['icon']) ?> text-secondary" aria-hidden="true"></i> <?= h($rec['text']) ?></span>
                    <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= h($rec['url']) ?>">Review</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Top Performing Batches (completed)</h2></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Batch</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
                    <tbody>
                        <?php if ($topBatches === []): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3">No completed batches with financial data yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($topBatches as $row): ?>
                                <tr>
                                    <td><a href="<?= h(app_url('/integrated/batch-costing/index.php')) ?>?action=view&amp;id=<?= (int) $row['flock']['flock_id'] ?>"><?= h($row['flock']['batch_code']) ?></a></td>
                                    <td class="text-end">₱<?= money($row['financials']['revenue']) ?></td>
                                    <td class="text-end">₱<?= money($row['financials']['total_cost']) ?></td>
                                    <td class="text-end text-success">₱<?= money($row['gross_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Lowest Performing Batches (completed)</h2></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Batch</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
                    <tbody>
                        <?php if ($bottomBatches === []): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-3">No completed batches with financial data yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bottomBatches as $row): ?>
                                <tr>
                                    <td><a href="<?= h(app_url('/integrated/batch-costing/index.php')) ?>?action=view&amp;id=<?= (int) $row['flock']['flock_id'] ?>"><?= h($row['flock']['batch_code']) ?></a></td>
                                    <td class="text-end">₱<?= money($row['financials']['revenue']) ?></td>
                                    <td class="text-end">₱<?= money($row['financials']['total_cost']) ?></td>
                                    <td class="text-end <?= $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($row['gross_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Low Stock vs Reorder Level</h2>
                <a class="small" href="<?= h(app_url('/pms/inventory/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="panel-body">
                <?php if ($lowStockRows === []): ?>
                    <p class="text-secondary mb-0">No items are currently below their reorder level.</p>
                <?php else: ?>
                    <canvas id="stockChart" height="<?= max(120, count($lowStockRows) * 36) ?>" role="img" aria-label="Current stock versus reorder level for low-stock items"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Replenishment Queue</h2>
                <a class="small" href="<?= h(app_url('/integrated/inventory-replenishment/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($replenishmentQueue === []): ?>
                            <tr><td class="text-center text-secondary py-3">No pending replenishment recommendations.</td></tr>
                        <?php else: ?>
                            <?php foreach ($replenishmentQueue as $row): ?>
                                <?php $urgency = replenishment_urgency_badge((string) $row['recommendation_status']); ?>
                                <tr>
                                    <td>
                                        <span class="<?= h(replenishment_urgency_badge_class($urgency['class'])) ?>"><i class="bi <?= h($urgency['icon']) ?>" aria-hidden="true"></i> <?= h((string) $row['recommendation_status']) ?></span>
                                        <strong class="ms-1"><?= h($row['item_name']) ?></strong>
                                        <div class="small text-secondary">Recommended: <?= number_format((float) $row['recommended_quantity'], 2) ?> &middot; Workflow: <?= h((string) $row['status']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="content-panel mb-3">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Scenario Analysis <span class="badge text-bg-warning text-dark">PROJECTED &mdash; simulation only</span></h2>
        <a class="small" href="<?= h(app_url('/integrated/scenarios/index.php')) ?>">Open Scenario Analysis &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Scenario</th><th class="text-end">Proposed Size</th><th class="text-end">Projected Revenue</th><th class="text-end">Projected Cost</th><th class="text-end">Projected Profit</th></tr></thead>
            <tbody>
                <?php if ($recentScenarios === []): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-3">No scenarios created yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentScenarios as $scenario): ?>
                        <?php $projectedProfit = (float) $scenario['projected_revenue'] - (float) $scenario['projected_cost']; ?>
                        <tr>
                            <td>
                                <?= h($scenario['scenario_name']) ?>
                                <?php if ((int) $scenario['is_auto_projected'] === 1): ?><span class="badge text-bg-success">AUTO-PROJECTED</span><?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((int) $scenario['proposed_flock_size']) ?></td>
                            <td class="text-end">₱<?= money($scenario['projected_revenue']) ?></td>
                            <td class="text-end">₱<?= money($scenario['projected_cost']) ?></td>
                            <td class="text-end <?= $projectedProfit >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($projectedProfit) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Integrated Analysis</h2></div>
    <div class="panel-body">
        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <a class="dashboard-module-link" href="<?= h(app_url('/integrated/batch-costing/index.php')) ?>">
                    <span><strong class="d-block">Batch Costing &amp; Profitability</strong><small class="text-secondary">Cost, revenue, and margin per batch</small></span>
                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a class="dashboard-module-link" href="<?= h(app_url('/integrated/cost-efficiency/index.php')) ?>">
                    <span><strong class="d-block">Cost Efficiency Analytics</strong><small class="text-secondary">Cost per bird and per category</small></span>
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a class="dashboard-module-link" href="<?= h(app_url('/integrated/inventory-replenishment/index.php')) ?>">
                    <span><strong class="d-block">Inventory Replenishment</strong><small class="text-secondary">Financially-aware stock recommendations</small></span>
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a class="dashboard-module-link" href="<?= h(app_url('/integrated/performance/index.php')) ?>">
                    <span><strong class="d-block">Production &amp; Financial Performance</strong><small class="text-secondary">Combined operational + financial KPIs</small></span>
                    <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                </a>
            </div>
            <div class="col-md-6 col-lg-4">
                <a class="dashboard-module-link" href="<?= h(app_url('/integrated/scenarios/index.php')) ?>">
                    <span><strong class="d-block">Scenario Analysis</strong><small class="text-secondary">Simulate flock and cost changes</small></span>
                    <i class="bi bi-lightbulb" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<?php if ($lowStockRows !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('stockChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($lowStockRows, 'item_name')) ?>,
        datasets: [
            { label: 'Current Stock', data: <?= json_encode(array_map('floatval', array_column($lowStockRows, 'quantity'))) ?>, backgroundColor: '#4fae72' },
            { label: 'Reorder Level', data: <?= json_encode(array_map('floatval', array_column($lowStockRows, 'reorder_level'))) ?>, backgroundColor: '#b3454a' }
        ]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { x: { beginAtZero: true } } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../shared/includes/footer.php'; ?>
