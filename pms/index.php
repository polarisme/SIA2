<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/includes/bootstrap.php';
require_authentication();

$activeNavigation = 'dashboard';
$pageTitle = 'Dashboard';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('PMS dashboard database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$counts = [
    'active_flocks' => 0, 'current_birds' => 0, 'low_stock_items' => 0,
    'due_or_overdue_activities' => 0, 'open_health_cases' => 0, 'eggs_7d' => 0, 'eggs_prev_7d' => 0,
    'mortality_7d' => 0, 'layer_flocks' => 0, 'broiler_flocks' => 0,
];
$healthAlerts = ['disease_clusters' => [], 'high_mortality' => [], 'stale_cases' => []];
$placementTrend = [];
$mortalityTrend = [];
$eggTrend = [];
$activeFlockRows = [];
$layerFlockRows = [];
$recentHealth = [];
$dueOrOverdueActivities = [];
$upcomingActivities = [];
$lowStockRows = [];

if ($pdo instanceof PDO) {
    try {
        $healthAlerts = pms_health_alerts($pdo);

        $flockRow = $pdo->query(
            "SELECT COUNT(*) AS active_flocks, COALESCE(SUM(current_birds), 0) AS current_birds
             FROM flocks WHERE deleted_at IS NULL AND status = 'Active'"
        )->fetch();
        $counts['active_flocks'] = (int) $flockRow['active_flocks'];
        $counts['current_birds'] = (int) $flockRow['current_birds'];

        $counts['low_stock_items'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM poultry_inventory WHERE deleted_at IS NULL AND status IN ('Low Stock', 'Out of Stock')"
        )->fetchColumn();

        $counts['due_or_overdue_activities'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM farm_activities
             WHERE deleted_at IS NULL AND status IN ('Scheduled', 'In Progress') AND scheduled_date <= CURDATE()"
        )->fetchColumn();

        $counts['open_health_cases'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM health_records WHERE deleted_at IS NULL AND status IN ('Observed', 'Under Treatment')"
        )->fetchColumn();

        $counts['eggs_7d'] = (int) $pdo->query(
            "SELECT COALESCE(SUM(eggs_produced), 0) FROM production_records
             WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
        )->fetchColumn();
        $counts['eggs_prev_7d'] = (int) $pdo->query(
            "SELECT COALESCE(SUM(eggs_produced), 0) FROM production_records
             WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND record_date < DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
        )->fetchColumn();

        $counts['mortality_7d'] = (int) $pdo->query(
            "SELECT COALESCE(SUM(mortality_count), 0) FROM production_records
             WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
        )->fetchColumn();

        // The schema has no broiler/layer column — a flock is treated as "egg-producing"
        // (layer-like) if it has ever recorded eggs_produced > 0, since that's the only
        // real signal the data gives us. This is a behavioral inference, not a stored fact.
        $typeSplit = $pdo->query(
            "SELECT
                SUM(f.status = 'Active' AND egg_flocks.flock_id IS NOT NULL) AS layer_active,
                SUM(f.status = 'Active' AND egg_flocks.flock_id IS NULL) AS broiler_active
             FROM flocks f
             LEFT JOIN (SELECT flock_id FROM production_records WHERE deleted_at IS NULL AND eggs_produced > 0 GROUP BY flock_id) egg_flocks
                ON egg_flocks.flock_id = f.flock_id
             WHERE f.deleted_at IS NULL"
        )->fetch();
        $counts['layer_flocks'] = (int) ($typeSplit['layer_active'] ?? 0);
        $counts['broiler_flocks'] = (int) ($typeSplit['broiler_active'] ?? 0);

        // Placements by month (last 12 months of flock start dates).
        $placementRows = $pdo->query(
            "SELECT DATE_FORMAT(start_date, '%Y-%m') AS ym, COUNT(*) AS n
             FROM flocks WHERE deleted_at IS NULL AND start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY ym ORDER BY ym"
        )->fetchAll();
        $placementTrend = array_map(static fn ($r) => ['label' => $r['ym'], 'value' => (int) $r['n']], $placementRows);

        // Mortality by week (last 12 weeks, Monday-start buckets).
        $mortalityRows = $pdo->query(
            "SELECT DATE(DATE_SUB(record_date, INTERVAL WEEKDAY(record_date) DAY)) AS week_start, COALESCE(SUM(mortality_count), 0) AS n
             FROM production_records
             WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
             GROUP BY week_start ORDER BY week_start"
        )->fetchAll();
        $mortalityTrend = array_map(static fn ($r) => ['label' => $r['week_start'], 'value' => (int) $r['n']], $mortalityRows);

        // Egg production by week (last 12 weeks).
        $eggRows = $pdo->query(
            "SELECT DATE(DATE_SUB(record_date, INTERVAL WEEKDAY(record_date) DAY)) AS week_start, COALESCE(SUM(eggs_produced), 0) AS n
             FROM production_records
             WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
             GROUP BY week_start ORDER BY week_start"
        )->fetchAll();
        $eggTrend = array_map(static fn ($r) => ['label' => $r['week_start'], 'value' => (int) $r['n']], $eggRows);

        // Active flock monitoring table, with each flock's most recent recorded weight sample.
        $activeFlockRows = $pdo->query(
            "SELECT f.*, egg_flocks.flock_id AS is_layer,
                    (SELECT p.average_weight FROM production_records p
                      WHERE p.flock_id = f.flock_id AND p.deleted_at IS NULL AND p.average_weight > 0
                      ORDER BY p.record_date DESC LIMIT 1) AS latest_weight,
                    (SELECT COALESCE(SUM(p2.eggs_produced), 0) FROM production_records p2
                      WHERE p2.flock_id = f.flock_id AND p2.deleted_at IS NULL AND p2.record_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) AS recent_eggs
             FROM flocks f
             LEFT JOIN (SELECT flock_id FROM production_records WHERE deleted_at IS NULL AND eggs_produced > 0 GROUP BY flock_id) egg_flocks
                ON egg_flocks.flock_id = f.flock_id
             WHERE f.deleted_at IS NULL AND f.status = 'Active'
             ORDER BY f.batch_code"
        )->fetchAll();
        $layerFlockRows = array_values(array_filter($activeFlockRows, static fn ($f) => $f['is_layer'] !== null));
        $activeFlockRows = array_values(array_filter($activeFlockRows, static fn ($f) => $f['is_layer'] === null));
        $activeFlockTotal = count($activeFlockRows);
        $layerFlockTotal = count($layerFlockRows);
        $activeFlockRows = array_slice($activeFlockRows, 0, 5);
        $layerFlockRows = array_slice($layerFlockRows, 0, 5);

        $recentHealth = $pdo->query(
            "SELECT h.*, f.batch_code FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id
             WHERE h.deleted_at IS NULL AND f.deleted_at IS NULL ORDER BY h.record_date DESC, h.health_id DESC LIMIT 5"
        )->fetchAll();

        $dueOrOverdueActivities = $pdo->query(
            "SELECT a.*, f.batch_code FROM farm_activities a LEFT JOIN flocks f ON f.flock_id = a.flock_id
             WHERE a.deleted_at IS NULL AND a.status IN ('Scheduled', 'In Progress') AND a.scheduled_date <= CURDATE()
             ORDER BY a.scheduled_date ASC LIMIT 5"
        )->fetchAll();

        $upcomingActivities = $pdo->query(
            "SELECT a.*, f.batch_code FROM farm_activities a LEFT JOIN flocks f ON f.flock_id = a.flock_id
             WHERE a.deleted_at IS NULL AND a.status = 'Scheduled' AND a.scheduled_date > CURDATE()
               AND a.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY a.scheduled_date ASC LIMIT 5"
        )->fetchAll();

        $lowStockRows = $pdo->query(
            "SELECT item_code, item_name, category, quantity, unit, reorder_level, status FROM poultry_inventory
             WHERE deleted_at IS NULL AND status IN ('Low Stock', 'Out of Stock') ORDER BY status DESC, quantity ASC LIMIT 5"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('PMS dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Some dashboard figures are unavailable because of a database error.';
    }
}

$mortalityRate7d = $counts['current_birds'] > 0 ? ($counts['mortality_7d'] / $counts['current_birds']) * 100 : null;
$eggChangePercent = $counts['eggs_prev_7d'] > 0 ? (($counts['eggs_7d'] - $counts['eggs_prev_7d']) / $counts['eggs_prev_7d']) * 100 : null;
$alertCount = count($healthAlerts['disease_clusters']) + count($healthAlerts['high_mortality']) + count($healthAlerts['stale_cases']);

function pms_badge(string $type, string $status): string
{
    return match ($type) {
        'flock' => match ($status) { 'Active' => 'success', 'Completed' => 'primary', 'Sold' => 'secondary', 'Terminated' => 'danger', default => 'light' },
        'health' => match ($status) { 'Observed' => 'secondary', 'Under Treatment' => 'warning', 'Resolved' => 'success', 'Monitoring' => 'info', default => 'light' },
        'activity' => match ($status) { 'Scheduled' => 'primary', 'In Progress' => 'warning', 'Completed' => 'success', 'Missed' => 'danger', 'Cancelled' => 'secondary', default => 'light' },
        'inventory' => match ($status) { 'Available' => 'success', 'Low Stock' => 'warning', 'Out of Stock' => 'danger', default => 'secondary' },
        default => 'secondary',
    };
}

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">POULTRY MANAGEMENT &middot; OPERATIONS OVERVIEW</div>
    <h1>Dashboard</h1>
    <p>Current farm status, production trends, and what needs attention today.</p>
</div>

<?php if ($databaseError !== null): ?><div class="alert alert-warning" role="status"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/flocks/index.php')) ?>">
            <div class="stat-label">Active Flocks</div>
            <div class="stat-value"><?= number_format($counts['active_flocks']) ?></div>
            <div class="small text-secondary mt-1"><?= number_format($counts['current_birds']) ?> birds total</div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/flocks/index.php')) ?>">
            <div class="stat-label">Broiler Batches / Layer Flocks (Active)</div>
            <div class="stat-value"><?= $counts['broiler_flocks'] ?> / <?= $counts['layer_flocks'] ?></div>
            <div class="small text-secondary mt-1">Layer = has recorded egg production</div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/production/index.php')) ?>">
            <div class="stat-label">Mortality Rate (Last 7 Days)</div>
            <div class="stat-value <?= $mortalityRate7d !== null && $mortalityRate7d > 1 ? 'text-danger' : '' ?>"><?= $mortalityRate7d === null ? '—' : number_format($mortalityRate7d, 2) . '%' ?></div>
            <div class="small text-secondary mt-1"><?= number_format($counts['mortality_7d']) ?> birds, of current active population</div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/production/index.php')) ?>">
            <div class="stat-label">Eggs Produced (Last 7 Days)</div>
            <div class="stat-value"><?= number_format($counts['eggs_7d']) ?></div>
            <div class="small mt-1 <?= $eggChangePercent === null ? 'text-secondary' : ($eggChangePercent >= 0 ? 'text-success' : 'text-danger') ?>">
                <?= $eggChangePercent === null ? 'No prior-week data to compare' : (($eggChangePercent >= 0 ? '+' : '') . number_format($eggChangePercent, 1) . '% vs previous 7 days') ?>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/inventory/index.php')) ?>">
            <div class="stat-label">Low / Out-of-Stock Items</div>
            <div class="stat-value <?= $counts['low_stock_items'] > 0 ? 'text-danger' : '' ?>"><?= number_format($counts['low_stock_items']) ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-4">
        <a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/activities/index.php')) ?>?overdue_only=1">
            <div class="stat-label">Due Today / Overdue Activities</div>
            <div class="stat-value <?= $counts['due_or_overdue_activities'] > 0 ? 'text-danger' : '' ?>"><?= number_format($counts['due_or_overdue_activities']) ?></div>
        </a>
    </div>
</div>

<?php if ($alertCount > 0): ?>
    <section class="content-panel mb-3">
        <div class="panel-heading d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0"><i class="bi bi-exclamation-triangle-fill text-danger" aria-hidden="true"></i> Health &amp; Mortality Early Warnings</h2>
            <a class="btn btn-sm btn-outline-danger" href="<?= h(app_url('/pms/health/index.php')) ?>">View Health Records</a>
        </div>
        <div class="panel-body">
            <?php if ($healthAlerts['disease_clusters'] !== []): ?>
                <div class="alert alert-danger mb-2" role="alert">
                    <strong>Disease clusters:</strong>
                    <?php foreach ($healthAlerts['disease_clusters'] as $cluster): ?>
                        Batch <?= h($cluster['batch_code']) ?> has <?= (int) $cluster['disease_count'] ?> disease record(s) in the last 7 days.<br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($healthAlerts['high_mortality'] !== []): ?>
                <div class="alert alert-danger mb-2" role="alert">
                    <strong>High mortality:</strong>
                    <?php foreach ($healthAlerts['high_mortality'] as $row): ?>
                        Batch <?= h($row['batch_code']) ?>: <?= number_format((float) $row['mortality_rate'], 2) ?>% mortality in the last 7 days (<?= (int) $row['mortality_7d'] ?> of <?= number_format((int) $row['current_birds']) ?> birds).<br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($healthAlerts['stale_cases'] !== []): ?>
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Long-open health cases:</strong>
                    <?php foreach ($healthAlerts['stale_cases'] as $case): ?>
                        Batch <?= h($case['batch_code']) ?>: <?= h($case['record_type']) ?> case still "<?= h($case['status']) ?>" after <?= (int) $case['days_open'] ?> days.<br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Flock Placements by Month</h2></div>
            <div class="panel-body">
                <?php if ($placementTrend === []): ?>
                    <p class="text-secondary mb-0">No flocks placed in the last 12 months.</p>
                <?php else: ?>
                    <canvas id="placementChart" height="200" role="img" aria-label="New flocks placed per month, last 12 months"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Mortality by Week</h2></div>
            <div class="panel-body">
                <?php if ($mortalityTrend === []): ?>
                    <p class="text-secondary mb-0">No mortality recorded in the last 12 weeks.</p>
                <?php else: ?>
                    <canvas id="mortalityChart" height="200" role="img" aria-label="Birds lost per week, last 12 weeks"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Egg Production by Week</h2></div>
            <div class="panel-body">
                <?php if ($eggTrend === []): ?>
                    <p class="text-secondary mb-0">No egg production recorded in the last 12 weeks.</p>
                <?php else: ?>
                    <canvas id="eggChart" height="200" role="img" aria-label="Eggs produced per week, last 12 weeks"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Active Broiler Batches</h2>
                <a class="small" href="<?= h(app_url('/pms/flocks/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Batch</th><th>Age</th><th class="text-end">Current / Initial</th><th class="text-end">Mortality</th><th class="text-end">Latest Weight</th></tr></thead>
                    <tbody>
                        <?php if ($activeFlockRows === []): ?>
                            <tr><td colspan="5" class="text-center text-secondary py-3">No active broiler batches.</td></tr>
                        <?php else: ?>
                            <?php foreach ($activeFlockRows as $flock): ?>
                                <?php $age = pms_flock_age((string) $flock['start_date'], (int) $flock['age_at_arrival_days']); ?>
                                <tr>
                                    <td><a href="<?= h(app_url('/pms/flocks/index.php')) ?>?action=view&amp;id=<?= (int) $flock['flock_id'] ?>"><?= h($flock['batch_code']) ?></a></td>
                                    <td><?= $age['days'] ?> days</td>
                                    <td class="text-end"><?= number_format((int) $flock['current_birds']) ?> / <?= number_format((int) $flock['initial_birds']) ?></td>
                                    <td class="text-end"><?= number_format(max(0, (int) $flock['initial_birds'] - (int) $flock['current_birds'])) ?></td>
                                    <td class="text-end"><?= $flock['latest_weight'] !== null ? number_format((float) $flock['latest_weight'], 2) . ' kg' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Active Layer Flocks</h2>
                <a class="small" href="<?= h(app_url('/pms/flocks/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Flock</th><th class="text-end">Population</th><th class="text-end">Eggs (7d)</th></tr></thead>
                    <tbody>
                        <?php if ($layerFlockRows === []): ?>
                            <tr><td colspan="3" class="text-center text-secondary py-3">No active layer flocks.</td></tr>
                        <?php else: ?>
                            <?php foreach ($layerFlockRows as $flock): ?>
                                <tr>
                                    <td><a href="<?= h(app_url('/pms/flocks/index.php')) ?>?action=view&amp;id=<?= (int) $flock['flock_id'] ?>"><?= h($flock['batch_code']) ?></a></td>
                                    <td class="text-end"><?= number_format((int) $flock['current_birds']) ?></td>
                                    <td class="text-end"><?= number_format((int) $flock['recent_eggs']) ?></td>
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
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Due / Overdue Activities</h2>
                <a class="small" href="<?= h(app_url('/pms/activities/index.php')) ?>?overdue_only=1">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($dueOrOverdueActivities === []): ?>
                            <tr><td class="text-center text-secondary py-3">Nothing due or overdue.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dueOrOverdueActivities as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= pms_badge('activity', $activity['status']) ?>"><?= h($activity['activity_type']) ?></span>
                                        <?php if ($activity['scheduled_date'] < date('Y-m-d')): ?><span class="badge text-bg-danger">Overdue</span><?php endif; ?>
                                        <div class="small text-secondary mt-1"><?= h($activity['batch_code'] ?? 'Farm-wide') ?> &middot; due <?= h((string) $activity['scheduled_date']) ?></div>
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
                <h2 class="h5 mb-0">Upcoming (Next 7 Days)</h2>
                <a class="small" href="<?= h(app_url('/pms/activities/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($upcomingActivities === []): ?>
                            <tr><td class="text-center text-secondary py-3">Nothing scheduled in the next 7 days.</td></tr>
                        <?php else: ?>
                            <?php foreach ($upcomingActivities as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= pms_badge('activity', $activity['status']) ?>"><?= h($activity['activity_type']) ?></span>
                                        <div class="small text-secondary mt-1"><?= h($activity['batch_code'] ?? 'Farm-wide') ?> &middot; due <?= h((string) $activity['scheduled_date']) ?></div>
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
                <h2 class="h5 mb-0">Recent Health Records</h2>
                <a class="small" href="<?= h(app_url('/pms/health/index.php')) ?>">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentHealth === []): ?>
                            <tr><td class="text-center text-secondary py-3">No health records yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentHealth as $record): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= pms_badge('health', $record['status']) ?>"><?= h($record['record_type']) ?></span>
                                        <span class="badge text-bg-<?= pms_badge('health', $record['status']) ?> border"><?= h($record['status']) ?></span>
                                        <div class="small text-secondary mt-1"><?= h($record['batch_code']) ?> &middot; <?= h((string) $record['record_date']) ?></div>
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

<?php if ($lowStockRows !== []): ?>
<section class="content-panel mb-3">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Inventory Needing Attention</h2>
        <a class="small" href="<?= h(app_url('/pms/inventory/index.php')) ?>">View all &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Item</th><th>Category</th><th class="text-end">Stock</th><th class="text-end">Reorder Level</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($lowStockRows as $item): ?>
                    <tr>
                        <td><a href="<?= h(app_url('/pms/inventory/index.php')) ?>"><?= h($item['item_name']) ?></a> <span class="text-secondary small"><?= h($item['item_code']) ?></span></td>
                        <td><?= h($item['category']) ?></td>
                        <td class="text-end"><?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                        <td class="text-end"><?= number_format((float) $item['reorder_level'], 2) ?></td>
                        <td><span class="badge text-bg-<?= pms_badge('inventory', $item['status']) ?>"><?= h($item['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Quick Actions</h2></div>
    <div class="panel-body d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= h(app_url('/pms/flocks/index.php')) ?>?action=add">Add Flock</a>
        <a class="btn btn-outline-primary" href="<?= h(app_url('/pms/activities/index.php')) ?>?action=add">Record Farm Activity</a>
        <a class="btn btn-outline-primary" href="<?= h(app_url('/pms/health/index.php')) ?>?action=add">Record Health Observation</a>
        <a class="btn btn-outline-primary" href="<?= h(app_url('/pms/production/index.php')) ?>?action=add">Record Production / Egg Collection</a>
        <a class="btn btn-outline-secondary" href="<?= h(app_url('/pms/inventory/index.php')) ?>">Review Inventory</a>
    </div>
</section>

<?php if ($placementTrend !== [] || $mortalityTrend !== [] || $eggTrend !== []): ?>
<script src="<?= h(versioned_asset_url('/shared/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script>
<?php if ($placementTrend !== []): ?>
new Chart(document.getElementById('placementChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($placementTrend, 'label')) ?>,
        datasets: [{ label: 'Flocks Placed', data: <?= json_encode(array_column($placementTrend, 'value')) ?>, backgroundColor: '#ed7e04' }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
<?php if ($mortalityTrend !== []): ?>
new Chart(document.getElementById('mortalityChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($mortalityTrend, 'label')) ?>,
        datasets: [{ label: 'Birds Lost', data: <?= json_encode(array_column($mortalityTrend, 'value')) ?>, borderColor: '#b3454a', backgroundColor: '#b3454a', tension: .3 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
<?php if ($eggTrend !== []): ?>
new Chart(document.getElementById('eggChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($eggTrend, 'label')) ?>,
        datasets: [{ label: 'Eggs Produced', data: <?= json_encode(array_column($eggTrend, 'value')) ?>, borderColor: '#f9af10', backgroundColor: '#f9af10', tension: .3 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
<?php endif; ?>
</script>
<?php endif; ?>
<?php require __DIR__ . '/../shared/includes/footer.php'; ?>
