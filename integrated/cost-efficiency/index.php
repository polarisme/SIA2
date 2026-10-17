<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_cost_efficiency';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$pageTitle = 'Cost Efficiency Analytics';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated cost efficiency database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$flockRows = [];
$categoryValuesByFlock = [];
if ($pdo instanceof PDO) {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code, breed, status, current_birds FROM flocks WHERE deleted_at IS NULL ORDER BY status, batch_code')->fetchAll();

        $eggsStatement = $pdo->prepare('SELECT COALESCE(SUM(eggs_produced), 0) FROM production_records WHERE flock_id = ? AND deleted_at IS NULL');
        $activitiesStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM farm_activities WHERE flock_id = ? AND status = 'Completed' AND deleted_at IS NULL"
        );
        $healthIncidentsStatement = $pdo->prepare('SELECT COUNT(*) FROM health_records WHERE flock_id = ? AND deleted_at IS NULL');

        // First pass: gather each flock's cost-by-category and production. Values are
        // kept PER FLOCK (not pre-averaged) so that each flock's own comparison, below,
        // can exclude itself from the average of every OTHER flock with data in that
        // category — matching this page's own tooltip/caption text.
        $categoryValuesByFlock = [];
        foreach ($flocks as $flock) {
            $flockId = (int) $flock['flock_id'];
            $financials = integrated_flock_financials($pdo, $flockId);
            $eggsStatement->execute([$flockId]);
            $eggs = (int) $eggsStatement->fetchColumn();
            $activitiesStatement->execute([$flockId]);
            $activitiesCompleted = (int) $activitiesStatement->fetchColumn();
            $healthIncidentsStatement->execute([$flockId]);
            $healthIncidents = (int) $healthIncidentsStatement->fetchColumn();
            $birds = max(1, (int) $flock['current_birds']); // avoid division by zero; birds=0 flocks are flagged separately below

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
                'eggs' => $eggs,
                'activities_completed' => $activitiesCompleted,
                'health_incidents' => $healthIncidents,
                'cost_per_bird_total' => (int) $flock['current_birds'] > 0 ? $financials['total_cost'] / $birds : null,
                'cost_per_100_eggs' => $eggs > 0 ? ($financials['total_cost'] / $eggs) * 100 : null,
                'cost_per_bird_by_category' => $costPerBirdByCategory,
            ];
        }
    } catch (PDOException $exception) {
        error_log('Integrated cost efficiency query failed: ' . $exception->getMessage());
        $databaseError = 'Cost efficiency data could not be loaded because of a database error.';
    }
}

$pageBodyClass = 'page-cost-efficiency';
$printReportTitle = 'YIELD — Cost Efficiency Analytics Report';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading d-print-none">
    <div class="eyebrow">OPERATIONAL COST EFFICIENCY ANALYTICS</div>
    <h1>Cost Efficiency Analytics</h1>
    <p>Compares actual financial cost against operational scale, so differently-sized batches can be compared fairly.</p>
    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Export</button>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">Cost per Batch</h2></div>
    <div>
        <table class="table table-hover mb-0">
            <thead><tr><th>Batch</th><th class="text-end">Current Birds</th><th class="text-end">Total Cost</th><th class="text-end">Cost per Bird</th><th class="text-end">Eggs Produced</th><th class="text-end">Cost per 100 Eggs</th><th class="text-end">Activities Completed</th><th class="text-end">Health Incidents</th></tr></thead>
            <tbody>
                <?php if ($flockRows === []): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-4">No flocks found.</td></tr>
                <?php else: ?>
                    <?php foreach ($flockRows as $row): ?>
                        <tr>
                            <td><?= h($row['flock']['batch_code']) ?> <span class="badge text-bg-secondary"><?= h($row['flock']['status']) ?></span></td>
                            <td class="text-end"><?= number_format((int) $row['flock']['current_birds']) ?></td>
                            <td class="text-end">₱<?= money($row['financials']['total_cost']) ?></td>
                            <td class="text-end"><?= $row['cost_per_bird_total'] === null ? '—' : '₱' . money($row['cost_per_bird_total']) ?></td>
                            <td class="text-end"><?= number_format($row['eggs']) ?></td>
                            <td class="text-end"><?= $row['cost_per_100_eggs'] === null ? '—' : '₱' . money($row['cost_per_100_eggs']) ?></td>
                            <td class="text-end"><?= number_format($row['activities_completed']) ?></td>
                            <td class="text-end"><?= number_format($row['health_incidents']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-body d-print-none">
        <p class="small text-secondary mb-0">"Cost per bird" divides total recorded cost by the batch's current bird count — a batch with 0 current birds cannot show this metric (division by zero), which is why it's shown as "—" rather than an invented number. "Cost per 100 eggs" is total cost divided by total eggs produced, scaled to a comparable unit.</p>
    </div>
</section>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">Cost Distribution by Category (per batch, cost per bird)</h2></div>
    <div>
        <table class="table table-sm mb-0">
            <thead><tr><th>Batch</th><?php foreach (INTEGRATED_COST_CATEGORIES as $category): ?><th class="text-end"><?= h($category) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($flockRows as $row): ?>
                    <tr>
                        <td><?= h($row['flock']['batch_code']) ?></td>
                        <?php foreach (INTEGRATED_COST_CATEGORIES as $category): ?>
                            <?php
                            $flockId = (int) $row['flock']['flock_id'];
                            $value = $row['cost_per_bird_by_category'][$category] ?? null;
                            // Average of every OTHER flock with data in this category — the flock
                            // being displayed is excluded from its own comparison average.
                            $othersInCategory = $categoryValuesByFlock[$category] ?? [];
                            unset($othersInCategory[$flockId]);
                            $sampleSize = count($othersInCategory);
                            $average = $sampleSize > 0 ? array_sum($othersInCategory) / $sampleSize : null;
                            $isAboveAverage = $value !== null && $average !== null && $sampleSize >= 2 && $value > $average * 1.5;
                            ?>
                            <td class="text-end">
                                <?php if ($value === null): ?>
                                    —
                                <?php else: ?>
                                    ₱<?= money($value) ?>
                                    <?php if ($isAboveAverage): ?>
                                        <br><span class="badge text-bg-warning" title="This batch's <?= h($category) ?> cost per bird (₱<?= money($value) ?>) is more than 1.5x the farm average of ₱<?= money($average) ?> across <?= $sampleSize ?> comparable batches.">Above average</span>
                                    <?php elseif ($sampleSize < 2 && $value !== null): ?>
                                        <br><small class="text-secondary">Not enough comparable batches yet</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-body d-print-none">
        <p class="small text-secondary mb-0">
            "Above average" is flagged only when a batch's cost-per-bird for a category is more than 1.5&times; the average of at least 2 other comparable batches with recorded cost in that category — this is a transparent, fixed rule, not a claim of inefficiency. A high absolute cost alone does not trigger this flag; only a high cost relative to batch size does.
        </p>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
