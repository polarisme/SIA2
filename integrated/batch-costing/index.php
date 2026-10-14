<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_batch_costing';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated batch costing database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'add_cost') {
        $flockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
        $costDate = trim((string) ($_POST['cost_date'] ?? ''));
        $costCategory = (string) ($_POST['cost_category'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $amountRaw = (string) ($_POST['amount'] ?? '');
        $sourceReference = trim((string) ($_POST['source_reference'] ?? ''));

        $errors = [];
        if ($flockId === false || $flockId < 1) {
            $errors[] = 'Invalid flock.';
        }
        if (!ais_valid_date($costDate)) {
            $errors[] = 'Enter a valid cost date.';
        }
        if (!in_array($costCategory, INTEGRATED_COST_CATEGORIES, true)) {
            $errors[] = 'Select a valid cost category.';
        }
        $amount = ais_parse_amount($amountRaw);
        if ($amount === false) {
            $errors[] = 'Enter a valid amount greater than zero.';
        }
        if (mb_strlen($description) > 255) {
            $errors[] = 'Description must be 255 characters or fewer.';
        }

        if ($errors === [] && $flockId !== false) {
            $exists = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $exists->execute([$flockId]);
            if ($exists->fetchColumn() === false) {
                $errors[] = 'The selected flock could not be found.';
            }
        }

        if ($errors === [] && $flockId !== false && $amount !== false) {
            $statement = $pdo->prepare(
                'INSERT INTO batch_costs (flock_id, cost_date, cost_category, description, amount, source_reference)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$flockId, $costDate, $costCategory, $description !== '' ? $description : null, $amount, $sourceReference !== '' ? $sourceReference : null]);
            integrated_recalculate_batch_profitability($pdo, $flockId);
            set_flash('success', 'Additional cost recorded for this batch.');
        } else {
            set_flash('danger', implode(' ', $errors) ?: 'The cost could not be recorded.');
        }
        redirect_to('index.php?action=view&id=' . ($flockId !== false ? $flockId : ''));
    } elseif ($action === 'delete_cost') {
        $costId = filter_var($_POST['cost_id'] ?? null, FILTER_VALIDATE_INT);
        $flockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
        if ($costId !== false && $costId >= 1) {
            if (integrated_soft_delete($pdo, 'batch_costs', $costId)) {
                if ($flockId !== false && $flockId >= 1) {
                    integrated_recalculate_batch_profitability($pdo, $flockId);
                }
                set_flash('success', 'Allocated cost moved to the Recycle Bin.');
            } else {
                set_flash('danger', 'The allocated cost could not be found.');
            }
        }
        redirect_to('index.php?action=view&id=' . ($flockId !== false ? $flockId : ''));
    }
}

// ---------------------------------------------------------------------------
// Detail view
// ---------------------------------------------------------------------------
if ($action === 'view' && $pdo instanceof PDO) {
    $flockId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $flock = null;
    if ($flockId !== false && $flockId >= 1) {
        $statement = $pdo->prepare('SELECT * FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
        $statement->execute([$flockId]);
        $flock = $statement->fetch();
    }
    if (!$flock) {
        set_flash('danger', 'The selected batch could not be found.');
        redirect_to('index.php');
    }

    $financials = integrated_recalculate_batch_profitability($pdo, $flockId);
    $grossProfit = $financials['revenue'] - $financials['total_cost'];
    $profitMargin = $financials['revenue'] > 0 ? ($grossProfit / $financials['revenue']) * 100 : null;

    $productionStatement = $pdo->prepare(
        'SELECT COUNT(*) AS records, COALESCE(SUM(eggs_produced), 0) AS eggs, COALESCE(SUM(mortality_count), 0) AS mortality,
                COALESCE(AVG(average_weight), 0) AS avg_weight
         FROM production_records WHERE flock_id = ? AND deleted_at IS NULL'
    );
    $productionStatement->execute([$flockId]);
    $production = $productionStatement->fetch();

    $allocatedCostsStatement = $pdo->prepare('SELECT * FROM batch_costs WHERE flock_id = ? AND deleted_at IS NULL ORDER BY cost_date DESC, cost_id DESC');
    $allocatedCostsStatement->execute([$flockId]);
    $allocatedCosts = $allocatedCostsStatement->fetchAll();

    $pageTitle = 'Batch Costing: ' . $flock['batch_code'];
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">BATCH COSTING &amp; PROFITABILITY</div>
        <h1><?= h($flock['batch_code']) ?></h1>
        <div class="d-flex gap-2 mt-2">
            <a class="btn btn-outline-secondary btn-sm" href="index.php">Back to batch list</a>
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Export</button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Breed</div><div class="stat-value fs-5"><?= h($flock['breed']) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Start Date</div><div class="stat-value fs-5"><?= h($flock['start_date']) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Initial / Current Birds</div><div class="stat-value fs-5"><?= number_format((int) $flock['initial_birds']) ?> / <?= number_format((int) $flock['current_birds']) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-label">Status</div><div class="stat-value fs-5"><span class="badge text-bg-secondary"><?= h($flock['status']) ?></span></div></div></div>
    </div>

    <?php if (!$financials['has_data']): ?>
        <div class="alert alert-warning" role="alert">No sufficient data available. No AIS financial transactions or allocated costs are linked to this batch yet.</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value text-success">₱<?= money($financials['revenue']) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Total Cost</div>
                    <div class="stat-value text-danger">₱<?= money($financials['total_cost']) ?></div>
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
        <?php if ($financials['revenue'] > 0 && !$financials['has_ais_transactions'] && !$financials['has_allocated_costs']): ?>
            <div class="alert alert-info" role="status">No cost transactions or allocated costs are recorded against this batch. The profit figure above reflects revenue only.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <section class="content-panel h-100">
                <div class="panel-heading"><h2 class="h5 mb-0">Production Information</h2></div>
                <div class="panel-body">
                    <?php if ((int) $production['records'] === 0): ?>
                        <p class="text-secondary mb-0">No production records for this batch.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-6"><div class="detail-label">Production records</div><div class="detail-value"><?= (int) $production['records'] ?></div></div>
                            <div class="col-6"><div class="detail-label">Eggs produced (total)</div><div class="detail-value"><?= number_format((int) $production['eggs']) ?></div></div>
                            <div class="col-6"><div class="detail-label">Mortality (total)</div><div class="detail-value"><?= number_format((int) $production['mortality']) ?></div></div>
                            <div class="col-6"><div class="detail-label">Average weight</div><div class="detail-value"><?= number_format((float) $production['avg_weight'], 2) ?></div></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="content-panel h-100">
                <div class="panel-heading"><h2 class="h5 mb-0">Operational / Batch Cost Details (by category)</h2></div>
                <div class="panel-body">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Category</th><th class="text-end">AIS Recorded</th><th class="text-end">Allocated</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            <?php foreach (INTEGRATED_COST_CATEGORIES as $category): ?>
                                <?php if ($financials['cost_by_category'][$category] <= 0) { continue; } ?>
                                <tr>
                                    <td><?= h($category) ?></td>
                                    <td class="text-end">₱<?= money($financials['ais_cost_by_category'][$category]) ?></td>
                                    <td class="text-end">₱<?= money($financials['allocated_cost_by_category'][$category]) ?></td>
                                    <td class="text-end fw-bold">₱<?= money($financials['cost_by_category'][$category]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($financials['total_cost'] <= 0): ?>
                                <tr><td colspan="4" class="text-center text-secondary py-3">No cost data recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <section class="content-panel mb-4">
        <div class="panel-heading"><h2 class="h5 mb-0">Financial Revenue (AIS Income Transactions Linked to This Batch)</h2></div>
        <div class="panel-body">
            <?php
            $incomeStatement = $pdo->prepare(
                "SELECT transaction_date, category, amount, status FROM financial_transactions
                 WHERE flock_id = ? AND transaction_type = 'Income' AND deleted_at IS NULL ORDER BY transaction_date DESC"
            );
            $incomeStatement->execute([$flockId]);
            $incomeRows = $incomeStatement->fetchAll();
            ?>
            <?php if ($incomeRows === []): ?>
                <p class="text-secondary mb-0">No income transactions are linked to this batch in AIS.</p>
            <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Date</th><th>Category</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($incomeRows as $row): ?>
                            <tr>
                                <td><?= h($row['transaction_date']) ?></td>
                                <td><?= h($row['category']) ?></td>
                                <td class="text-end">₱<?= money($row['amount']) ?></td>
                                <td><span class="badge text-bg-<?= h(ais_transaction_status_badge($row['status'])) ?>"><?= h($row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="small text-secondary mt-2 mb-0">Cancelled transactions (if any) are shown here but excluded from the revenue total above.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Additional Allocated Costs</h2></div>
        <div class="panel-body">
            <p class="small text-secondary">Use this only for costs not already recorded as an AIS transaction — for example, a share of farm-wide labor or utilities allocated to this batch.</p>
            <?php if ($allocatedCosts === []): ?>
                <p class="text-secondary">No allocated costs recorded for this batch.</p>
            <?php else: ?>
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="text-end">Amount</th><th>Source Ref.</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allocatedCosts as $cost): ?>
                            <tr>
                                <td><?= h($cost['cost_date']) ?></td>
                                <td><?= h($cost['cost_category']) ?></td>
                                <td><?= h($cost['description'] ?: '—') ?></td>
                                <td class="text-end">₱<?= money($cost['amount']) ?></td>
                                <td><?= h($cost['source_reference'] ?: '—') ?></td>
                                <td class="text-end">
                                    <form method="post" action="index.php?action=delete_cost" class="d-inline" onsubmit="return confirm('Move this allocated cost to the Recycle Bin?\nIt can be restored later.');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="cost_id" value="<?= (int) $cost['cost_id'] ?>">
                                        <input type="hidden" name="flock_id" value="<?= (int) $flockId ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <form method="post" action="index.php?action=add_cost" class="row g-2 align-items-end mt-2">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="flock_id" value="<?= (int) $flockId ?>">
                <div class="col-md-2">
                    <label class="form-label" for="cost_date">Date</label>
                    <input class="form-control" type="date" id="cost_date" name="cost_date" required value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="cost_category">Category</label>
                    <select class="form-select" id="cost_category" name="cost_category" required>
                        <?php foreach (INTEGRATED_COST_CATEGORIES as $category): ?><option value="<?= h($category) ?>"><?= h($category) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="amount">Amount (₱)</label>
                    <input class="form-control" type="number" id="amount" name="amount" min="0.01" step="0.01" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="source_reference">Source ref.</label>
                    <input class="form-control" id="source_reference" name="source_reference" maxlength="100">
                </div>
                <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Add</button></div>
            </form>
        </div>
    </section>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$flocks = [];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code, breed, status, current_birds FROM flocks WHERE deleted_at IS NULL ORDER BY status, batch_code')->fetchAll();
    } catch (PDOException $exception) {
        error_log('Integrated batch costing list query failed: ' . $exception->getMessage());
        $databaseError = 'Batches could not be loaded because of a database error.';
    }
}

$pageTitle = 'Batch Costing & Profitability';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">BATCH COSTING &amp; PROFITABILITY</div>
    <h1>Batch Costing &amp; Profitability</h1>
    <p>Select a batch to see its cost, revenue, and profit — calculated from live AIS and PMS records.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Batch</th><th>Breed</th><th>Status</th><th class="text-end">Current Birds</th><th class="text-end">Revenue</th><th class="text-end">Total Cost</th><th class="text-end">Gross Profit</th><th class="text-end">Margin</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($flocks === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No flocks found.</td></tr>
                <?php else: ?>
                    <?php foreach ($flocks as $flock): ?>
                        <?php
                        $f = integrated_flock_financials($pdo, (int) $flock['flock_id']);
                        $gp = $f['revenue'] - $f['total_cost'];
                        $margin = $f['revenue'] > 0 ? ($gp / $f['revenue']) * 100 : null;
                        ?>
                        <tr>
                            <td><?= h($flock['batch_code']) ?></td>
                            <td><?= h($flock['breed']) ?></td>
                            <td><span class="badge text-bg-secondary"><?= h($flock['status']) ?></span></td>
                            <td class="text-end"><?= number_format((int) $flock['current_birds']) ?></td>
                            <?php if (!$f['has_data']): ?>
                                <td colspan="4" class="text-center text-secondary">No sufficient data available</td>
                            <?php else: ?>
                                <td class="text-end">₱<?= money($f['revenue']) ?></td>
                                <td class="text-end">₱<?= money($f['total_cost']) ?></td>
                                <td class="text-end <?= $gp >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($gp) ?></td>
                                <td class="text-end"><?= $margin === null ? '—' : number_format($margin, 1) . '%' ?></td>
                            <?php endif; ?>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $flock['flock_id'] ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
