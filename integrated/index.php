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
$totals = ['income' => 0.0, 'expense' => 0.0, 'cost' => 0.0];
$attentionCount = 0;
if ($pdo instanceof PDO) {
    try {
        integrated_recalculate_all_batch_profitability($pdo);

        $flocks = $pdo->query('SELECT flock_id, batch_code, status, current_birds, initial_birds FROM flocks WHERE deleted_at IS NULL ORDER BY status, batch_code')->fetchAll();

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
    } catch (PDOException $exception) {
        error_log('Integrated dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Some dashboard figures are unavailable because of a database error.';
    }
}

$activeFlocks = array_filter($flocks, static fn (array $f): bool => $f['status'] === 'Active');
$totalBirds = array_sum(array_column($activeFlocks, 'current_birds'));
$grossProfit = $totals['income'] - $totals['cost'];
$profitMargin = $totals['income'] > 0 ? ($grossProfit / $totals['income']) * 100 : null;

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">YIELD INTEGRATED SYSTEM</div>
    <h1>Integrated Dashboard</h1>
    <p>Poultry operations and accounting information brought together for management decision-making.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Active Flocks</div>
            <div class="stat-value"><?= count($activeFlocks) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Total Birds (active flocks)</div>
            <div class="stat-value"><?= number_format((int) $totalBirds) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Production Output (eggs, all-time)</div>
            <div class="stat-value"><?= number_format((int) ($prodRow['production'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Inventory Items Requiring Attention</div>
            <div class="stat-value <?= $attentionCount > 0 ? 'text-danger' : '' ?>"><?= $attentionCount ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Total Operating Cost</div>
            <div class="stat-value text-danger">₱<?= money($totals['cost']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value text-success">₱<?= money($totals['income']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Gross Profit</div>
            <div class="stat-value <?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($grossProfit) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">Profit Margin</div>
            <div class="stat-value"><?= $profitMargin === null ? '—' : number_format($profitMargin, 1) . '%' ?></div>
        </div>
    </div>
</div>
<p class="small text-secondary mb-4">Cost and revenue totals are the sum of each active/existing flock's live recalculated profitability (AIS financial transactions linked via <code>flock_id</code>, plus any additional allocated costs). Mortality total across all flocks: <?= number_format((int) ($prodRow['mortality'] ?? 0)) ?> birds (from production records).</p>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">Batch Snapshot</h2></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Batch</th><th>Status</th><th class="text-end">Current Birds</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
            <tbody>
                <?php if ($flocks === []): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">No flocks found.</td></tr>
                <?php else: ?>
                    <?php foreach ($flocks as $flock): ?>
                        <?php $f = integrated_flock_financials($pdo, (int) $flock['flock_id']); $gp = $f['revenue'] - $f['total_cost']; ?>
                        <tr>
                            <td><a href="<?= h(app_url('/integrated/batch-costing/index.php')) ?>?action=view&amp;id=<?= (int) $flock['flock_id'] ?>"><?= h($flock['batch_code']) ?></a></td>
                            <td><span class="badge text-bg-secondary"><?= h($flock['status']) ?></span></td>
                            <td class="text-end"><?= number_format((int) $flock['current_birds']) ?></td>
                            <td class="text-end">₱<?= money($f['revenue']) ?></td>
                            <td class="text-end">₱<?= money($f['total_cost']) ?></td>
                            <td class="text-end <?= $gp >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($gp) ?></td>
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
<?php require __DIR__ . '/../shared/includes/footer.php'; ?>
