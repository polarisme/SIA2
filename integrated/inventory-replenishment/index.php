<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_replenishment';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated replenishment database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

function replenishment_form_data(array $source): array
{
    return [
        'item_id' => trim((string) ($source['item_id'] ?? '')),
        'expected_demand' => trim((string) ($source['expected_demand'] ?? '')),
        'unit_price' => trim((string) ($source['unit_price'] ?? '')),
        'available_budget' => trim((string) ($source['available_budget'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create') {
        $formData = replenishment_form_data($_POST);
        $errors = [];
        $itemId = filter_var($formData['item_id'], FILTER_VALIDATE_INT);
        if ($itemId === false || $itemId < 1) {
            $errors['item_id'] = 'Select an inventory item.';
        }
        $expectedDemand = ais_parse_nonnegative_amount($formData['expected_demand']);
        if ($expectedDemand === false) {
            $errors['expected_demand'] = 'Enter the expected demand (0 or more).';
        }
        $unitPrice = ais_parse_nonnegative_amount($formData['unit_price']);
        if ($unitPrice === false) {
            $errors['unit_price'] = 'Enter a valid unit price.';
        }
        $availableBudget = ais_parse_nonnegative_amount($formData['available_budget']);
        if ($availableBudget === false) {
            $errors['available_budget'] = 'Enter the available budget (0 or more).';
        }

        $item = null;
        if ($itemId !== false) {
            $statement = $pdo->prepare('SELECT * FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL');
            $statement->execute([$itemId]);
            $item = $statement->fetch();
            if (!$item) {
                $errors['item_id'] = 'The selected inventory item could not be found.';
            }
        }

        if ($errors === [] && $item !== null && $expectedDemand !== false && $unitPrice !== false && $availableBudget !== false) {
            $currentStock = (float) $item['quantity'];
            $reorderLevel = (float) $item['reorder_level'];
            $estimatedCost = round(max(0.0, $expectedDemand + $reorderLevel - $currentStock) * $unitPrice, 2);
            $result = integrated_replenishment_recommendation($currentStock, $reorderLevel, $expectedDemand, $estimatedCost, $availableBudget);

            $statement = $pdo->prepare(
                'INSERT INTO inventory_replenishment
                    (item_id, current_stock, expected_demand, recommended_quantity, unit_price, available_budget, recommendation, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $itemId, $currentStock, $expectedDemand, $result['recommended_quantity'], $unitPrice, $availableBudget,
                $result['status'] . ' — ' . $result['reason'], 'Pending',
            ]);
            set_flash('success', 'Replenishment analysis saved: ' . $result['status'] . '.');
        } else {
            set_flash('danger', $errors === [] ? 'The analysis could not be saved.' : implode(' ', $errors));
        }
        redirect_to('index.php');
    } elseif ($action === 'update_status') {
        $replenishmentId = filter_var($_POST['replenishment_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if ($replenishmentId !== false && $replenishmentId >= 1 && in_array($status, INTEGRATED_REPLENISHMENT_STATUSES, true)) {
            $pdo->prepare('UPDATE inventory_replenishment SET status = ? WHERE replenishment_id = ?')->execute([$status, $replenishmentId]);
            set_flash('success', 'Status updated.');
        } else {
            set_flash('danger', 'The status could not be updated.');
        }
        redirect_to('index.php');
    } elseif ($action === 'delete') {
        $replenishmentId = filter_var($_POST['replenishment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($replenishmentId !== false && $replenishmentId >= 1) {
            if (integrated_soft_delete($pdo, 'inventory_replenishment', $replenishmentId)) {
                set_flash('success', 'Replenishment analysis moved to the Recycle Bin.');
            } else {
                set_flash('danger', 'The replenishment analysis could not be found.');
            }
        }
        redirect_to('index.php');
    }
}

$items = [];
$analyses = [];
if ($pdo instanceof PDO) {
    try {
        $items = $pdo->query(
            "SELECT item_id, item_code, item_name, category, quantity, reorder_level, unit_cost, status
             FROM poultry_inventory WHERE deleted_at IS NULL ORDER BY status = 'Out of Stock' DESC, status = 'Low Stock' DESC, item_name"
        )->fetchAll();

        $analyses = $pdo->query(
            "SELECT r.*, i.item_code, i.item_name, i.category, i.quantity AS live_quantity, i.reorder_level AS live_reorder_level
             FROM inventory_replenishment r JOIN poultry_inventory i ON i.item_id = r.item_id
             WHERE r.deleted_at IS NULL
             ORDER BY r.created_at DESC"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('Integrated replenishment list query failed: ' . $exception->getMessage());
        $databaseError = 'Inventory data could not be loaded because of a database error.';
    }
}

$pageTitle = 'Inventory Replenishment';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">FINANCIALLY-AWARE INVENTORY REPLENISHMENT</div>
    <h1>Inventory Replenishment</h1>
    <p>Decide whether an item needs restocking, weighing operational demand against available budget.</p>
    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Export</button>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">New Replenishment Analysis</h2></div>
    <div class="panel-body">
        <p class="small text-secondary">Current stock and reorder level come live from Inventory Management. Expected demand and available budget are figures you enter — clearly your own projection, not a system-derived value.</p>
        <form method="post" action="index.php?action=create" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="col-md-3">
                <label class="form-label" for="item_id">Inventory item</label>
                <select class="form-select" id="item_id" name="item_id" required onchange="var o=this.options[this.selectedIndex]; document.getElementById('unit_price').value=o.dataset.unitCost||'';">
                    <option value="">Select item</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['item_id'] ?>" data-unit-cost="<?= h($item['unit_cost']) ?>">
                            <?= h($item['item_code']) ?> — <?= h($item['item_name']) ?> (stock: <?= number_format((float) $item['quantity'], 2) ?>, reorder: <?= number_format((float) $item['reorder_level'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="expected_demand">Expected demand <span class="text-danger">*</span></label>
                <input class="form-control" type="number" id="expected_demand" name="expected_demand" min="0" step="0.01" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="unit_price">Unit price (₱)</label>
                <input class="form-control" type="number" id="unit_price" name="unit_price" min="0" step="0.01" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="available_budget">Available budget (₱) <span class="text-danger">*</span></label>
                <input class="form-control" type="number" id="available_budget" name="available_budget" min="0" step="0.01" required>
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Calculate &amp; Save Analysis</button></div>
        </form>
        <p class="small text-secondary mt-2 mb-0">
            Rule used: <strong>Recommended Quantity = max(0, Expected Demand + Reorder Level − Current Stock)</strong>; Estimated Cost = Recommended Quantity &times; Unit Price.
            Status is <strong>Sufficient Stock</strong> when current stock already covers demand and the reorder buffer; <strong>High Priority Replenishment</strong> when stock is at/near zero or well below reorder level;
            <strong>Insufficient Budget</strong> when the estimated cost exceeds your stated budget; otherwise <strong>Replenishment Recommended</strong>.
        </p>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Replenishment Analyses</h2></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Item</th><th>Category</th><th class="text-end">Current Stock</th><th class="text-end">Reorder Level</th><th class="text-end">Expected Demand</th><th class="text-end">Recommended Qty</th><th class="text-end">Unit Price</th><th class="text-end">Est. Cost</th><th class="text-end">Available Budget</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($analyses === []): ?>
                    <tr><td colspan="11" class="text-center text-secondary py-4">No replenishment analyses recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($analyses as $row): ?>
                        <?php
                        $statusWords = explode(' — ', (string) $row['recommendation'], 2);
                        $recommendationLabel = $statusWords[0] ?? 'Replenishment Recommended';
                        $recommendationReason = $statusWords[1] ?? '';
                        ?>
                        <tr>
                            <td><?= h($row['item_code']) ?> — <?= h($row['item_name']) ?></td>
                            <td><?= h($row['category']) ?></td>
                            <td class="text-end"><?= number_format((float) $row['live_quantity'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['live_reorder_level'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['expected_demand'], 2) ?></td>
                            <td class="text-end"><?= number_format((float) $row['recommended_quantity'], 2) ?></td>
                            <td class="text-end">₱<?= money($row['unit_price']) ?></td>
                            <td class="text-end">₱<?= money($row['estimated_cost']) ?></td>
                            <td class="text-end">₱<?= money($row['available_budget']) ?></td>
                            <td>
                                <span class="badge text-bg-<?= h(replenishment_status_badge($recommendationLabel)) ?>" title="<?= h($recommendationReason) ?>"><?= h($recommendationLabel) ?></span>
                                <div class="small text-secondary mt-1">Workflow: <span class="badge text-bg-<?= h(replenishment_status_badge($row['status'])) ?>"><?= h($row['status']) ?></span></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="post" action="index.php?action=update_status" class="d-inline-flex gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                                    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                        <?php foreach (INTEGRATED_REPLENISHMENT_STATUSES as $status): ?>
                                            <option value="<?= h($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this replenishment analysis to the Recycle Bin?\nIt can be restored later.');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
