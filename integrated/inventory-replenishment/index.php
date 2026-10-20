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

/** Whole numbers show with no decimals; anything else keeps 2. Money always keeps 2 (use money() for that). */
function format_qty(float $value): string
{
    return abs($value - round($value)) < 0.0001 ? number_format($value, 0) : number_format($value, 2);
}

/**
 * Splits a stored "Status — reason" string using the row's own (reliable)
 * recommendation_status column as the known label to strip, rather than
 * re-parsing free text. Returns the reason text alone.
 */
function replenishment_parse_recommendation(string $recommendation, ?string $knownStatus): string
{
    $text = $recommendation;
    if ($knownStatus !== null) {
        $prefix = $knownStatus . ' — ';
        if (str_starts_with($text, $prefix)) {
            $text = substr($text, mb_strlen($prefix));
        }
    }

    return $text;
}

/** Precomputes every display value needed for one analysis row, shared by the table and the mobile-card rendering. */
function replenishment_row_view(array $row): array
{
    $status = (string) ($row['recommendation_status'] ?? 'Replenishment Recommended');
    $urgency = replenishment_urgency_badge($status);
    $reason = replenishment_parse_recommendation((string) $row['recommendation'], $row['recommendation_status']);
    $estimatedCost = (float) $row['estimated_cost'];
    $availableBudget = (float) $row['available_budget'];
    $overBudget = $estimatedCost > $availableBudget;

    return [
        'row' => $row,
        'status' => $status,
        'urgency_class' => $urgency['class'],
        'urgency_icon' => $urgency['icon'],
        'accent_class' => replenishment_urgency_accent_class($status),
        'reason' => $reason,
        'over_budget' => $overBudget,
        'budget_diff' => abs($estimatedCost - $availableBudget),
        'workflow_class' => replenishment_workflow_badge((string) $row['status']),
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
                    (item_id, current_stock, expected_demand, recommended_quantity, unit_price, available_budget, recommendation, recommendation_status, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $itemId, $currentStock, $expectedDemand, $result['recommended_quantity'], $unitPrice, $availableBudget,
                $result['status'] . ' — ' . $result['reason'], $result['status'], 'Pending',
            ]);
            audit_pms_action($pdo, 'CREATE', 'inventory_replenishment', (int) $pdo->lastInsertId());
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
            audit_pms_action($pdo, 'UPDATE', 'inventory_replenishment', $replenishmentId);
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
$analysesTotal = 0;
$cashPosition = 0.0;
['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
if ($pdo instanceof PDO) {
    try {
        $items = $pdo->query(
            "SELECT i.item_id, i.item_code, i.item_name, i.category, i.quantity, i.reorder_level, i.unit_cost, i.status,
                    COALESCE((
                        SELECT SUM(a.quantity) FROM inventory_adjustments a
                        WHERE a.item_id = i.item_id AND a.direction = 'Reduce' AND a.adjusted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ), 0) / 30 AS avg_daily_reduction
             FROM poultry_inventory i WHERE i.deleted_at IS NULL
             ORDER BY i.status = 'Out of Stock' DESC, i.status = 'Low Stock' DESC, i.item_name"
        )->fetchAll();

        // Same cash-position formula as the Statement of Financial Position (4.2b):
        // net of all non-cancelled transactions to date. Shown as a suggested
        // budget only — the field stays editable.
        $cashPosition = (float) $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE -amount END), 0)
             FROM financial_transactions WHERE status <> 'Cancelled' AND deleted_at IS NULL"
        )->fetchColumn();

        $analysesTotal = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_replenishment r WHERE r.deleted_at IS NULL"
        )->fetchColumn();

        $analyses = $pdo->query(
            "SELECT r.*, i.item_code, i.item_name, i.category, i.quantity AS live_quantity, i.reorder_level AS live_reorder_level
             FROM inventory_replenishment r JOIN poultry_inventory i ON i.item_id = r.item_id
             WHERE r.deleted_at IS NULL
             ORDER BY FIELD(r.recommendation_status, 'High Priority Replenishment', 'Insufficient Budget', 'Replenishment Recommended', 'Sufficient Stock'), r.created_at DESC
             LIMIT $perPage OFFSET $offset"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('Integrated replenishment list query failed: ' . $exception->getMessage());
        $databaseError = 'Inventory data could not be loaded because of a database error.';
    }
}

$pageTitle = 'Inventory Replenishment';
$pageBodyClass = 'page-replenishment';
$printReportTitle = 'YIELD — Inventory Replenishment Report';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading d-print-none">
    <div class="eyebrow">FINANCIALLY-AWARE INVENTORY REPLENISHMENT</div>
    <h1>Inventory Replenishment</h1>
    <p>Decide whether an item needs restocking, weighing operational demand against available budget.</p>
    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Export</button>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4 replenishment-form-panel d-print-none">
    <div class="panel-heading"><h2 class="h5 mb-0">New Replenishment Analysis</h2></div>
    <div class="panel-body">
        <p class="small text-secondary">Current stock and reorder level come live from Inventory Management. Expected demand and available budget are pre-filled as suggestions — from the item's recent usage and the current AIS cash position — but remain fully editable; they are not forced system values.</p>
        <form method="post" action="index.php?action=create" class="row g-2 align-items-start" id="replenishment-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="col-6 col-lg-3">
                <label class="form-label text-nowrap" for="item_id">Inventory item</label>
                <select class="form-select" id="item_id" name="item_id" required onchange="updateReplenishmentSuggestions();">
                    <option value="">Select item</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['item_id'] ?>" data-unit-cost="<?= h($item['unit_cost']) ?>" data-avg-daily-reduction="<?= h((string) $item['avg_daily_reduction']) ?>">
                            <?= h($item['item_code']) ?> — <?= h($item['item_name']) ?> (stock: <?= number_format((float) $item['quantity'], 2) ?>, reorder: <?= number_format((float) $item['reorder_level'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">&nbsp;</div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label text-nowrap" for="days_to_cover">Days to cover</label>
                <input class="form-control" type="number" id="days_to_cover" name="days_to_cover" min="1" step="1" value="30" onchange="updateReplenishmentSuggestions();">
                <div class="form-text">How many days of stock to buy for.</div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label text-nowrap" for="expected_demand">Expected demand <span class="text-danger">*</span></label>
                <input class="form-control" type="number" id="expected_demand" name="expected_demand" min="0" step="0.01" required>
                <div class="form-text">Suggested: avg. daily usage (30 days) &times; days to cover.</div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label text-nowrap" for="unit_price">Unit price (₱)</label>
                <input class="form-control" type="number" id="unit_price" name="unit_price" min="0" step="0.01" required>
                <div class="form-text">Pre-filled from inventory unit cost.</div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label text-nowrap" for="available_budget">Available budget (₱) <span class="text-danger">*</span></label>
                <input class="form-control" type="number" id="available_budget" name="available_budget" min="0" step="0.01" required value="<?= h(number_format(max(0.0, $cashPosition), 2, '.', '')) ?>">
                <div class="form-text">Suggested: current AIS cash position.</div>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label text-nowrap">&nbsp;</label>
                <button class="btn btn-primary w-100" type="submit">Save</button>
                <div class="form-text">&nbsp;</div>
            </div>
        </form>
        <button class="btn btn-link btn-sm px-0 mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#replenishment-howto" aria-expanded="false" aria-controls="replenishment-howto">
            How is this calculated?
        </button>
        <div class="collapse" id="replenishment-howto">
            <p class="small text-secondary mb-0">
                Rule used: <strong>Recommended Quantity = max(0, Expected Demand + Reorder Level − Current Stock)</strong>; Estimated Cost = Recommended Quantity &times; Supplier Unit Price.
                Status is <strong>Sufficient Stock</strong> when current stock already covers demand and the reorder buffer; <strong>High Priority Replenishment</strong> when stock is at/near zero or well below reorder level;
                <strong>Insufficient Budget</strong> when the estimated cost exceeds your stated budget; otherwise <strong>Replenishment Recommended</strong>.
            </p>
        </div>
        <script>
            function updateReplenishmentSuggestions() {
                var select = document.getElementById('item_id');
                var option = select.options[select.selectedIndex];
                if (!option || !option.value) { return; }
                var unitCost = parseFloat(option.dataset.unitCost || '0');
                var avgDailyReduction = parseFloat(option.dataset.avgDailyReduction || '0');
                var daysToCover = parseFloat(document.getElementById('days_to_cover').value || '30');
                document.getElementById('unit_price').value = unitCost.toFixed(2);
                document.getElementById('expected_demand').value = (avgDailyReduction * daysToCover).toFixed(2);
            }
        </script>
    </div>
</section>

<div class="content-panel mb-3 replenishment-legend">
    <div class="panel-body">
        <div class="small text-secondary mb-2 fw-semibold">Urgency legend</div>
        <div class="d-flex flex-wrap gap-3">
            <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i> High Priority Replenishment</span>
            <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-cash-coin" aria-hidden="true"></i> Insufficient Budget</span>
            <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle"><i class="bi bi-cart-plus" aria-hidden="true"></i> Replenishment Recommended</span>
            <span class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Sufficient Stock</span>
        </div>
    </div>
</div>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Replenishment Analyses</h2></div>

    <?php if ($analyses === []): ?>
        <div class="panel-body text-center text-secondary py-4">No replenishment analyses recorded yet.</div>
    <?php else: ?>
        <!-- Desktop/tablet (>=992px): fixed-layout table, no horizontal scroll -->
        <div class="d-none d-lg-block">
            <table class="table table-hover table-sm mb-0 replenishment-table table-compact">
                <thead>
                    <tr>
                        <th style="width: 20%">Item</th>
                        <th style="width: 13%">Stock</th>
                        <th style="width: 13%">Demand &rarr; Recommended</th>
                        <th style="width: 17%">Cost vs Budget</th>
                        <th style="width: 25%">Recommendation</th>
                        <th style="width: 12%">Workflow / Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analyses as $row): ?>
                        <?php $view = replenishment_row_view($row); ?>
                        <tr class="replenishment-row <?= h($view['accent_class']) ?>">
                            <td>
                                <div class="fw-bold"><?= h($row['item_name']) ?></div>
                                <div class="small text-secondary"><?= h($row['item_code']) ?> &middot; <?= h($row['category']) ?></div>
                            </td>
                            <td>
                                <div>Current: <?= format_qty((float) $row['live_quantity']) ?></div>
                                <div class="small text-secondary">Reorder level: <?= format_qty((float) $row['live_reorder_level']) ?></div>
                            </td>
                            <td>
                                <div>Demand: <?= format_qty((float) $row['expected_demand']) ?></div>
                                <div class="fw-bold">Recommended: <?= format_qty((float) $row['recommended_quantity']) ?></div>
                            </td>
                            <td>
                                <div><?= format_qty((float) $row['recommended_quantity']) ?> &times; ₱<?= money($row['unit_price']) ?> = ₱<?= money($row['estimated_cost']) ?></div>
                                <div class="small text-secondary">Stated budget: ₱<?= money($row['available_budget']) ?></div>
                                <?php if ($view['over_budget']): ?>
                                    <div class="small text-danger-emphasis fw-semibold">Budget check: ₱<?= money($view['budget_diff']) ?> over</div>
                                <?php else: ?>
                                    <div class="small text-success-emphasis fw-semibold">Budget check: within budget</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= h(replenishment_urgency_badge_class($view['urgency_class'])) ?>"><i class="bi <?= h($view['urgency_icon']) ?>" aria-hidden="true"></i> <?= h($view['status']) ?></span>
                                <p class="replenishment-reason mt-1 mb-0"><?= h($view['reason']) ?></p>
                            </td>
                            <td>
                                <div class="d-flex flex-column align-items-start gap-2">
                                    <span class="badge <?= h($view['workflow_class']) ?> d-inline-block">Workflow: <?= h($row['status']) ?></span>
                                    <form method="post" action="index.php?action=update_status" class="d-flex gap-1 d-print-none">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                                        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                            <?php foreach (INTEGRATED_REPLENISHMENT_STATUSES as $statusOption): ?>
                                                <option value="<?= h($statusOption) ?>" <?= $row['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <form method="post" action="index.php?action=delete" class="d-print-none" onsubmit="return confirm('Move this replenishment analysis to the Recycle Bin?\nIt can be restored later.');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile/small-laptop (<992px): stacked cards, never scrolls sideways -->
        <div class="d-lg-none">
            <?php foreach ($analyses as $row): ?>
                <?php $view = replenishment_row_view($row); ?>
                <div class="replenishment-card <?= h($view['accent_class']) ?> p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold"><?= h($row['item_name']) ?></div>
                            <div class="small text-secondary"><?= h($row['item_code']) ?> &middot; <?= h($row['category']) ?></div>
                        </div>
                    </div>
                    <div class="mt-2"><span class="<?= h(replenishment_urgency_badge_class($view['urgency_class'])) ?>"><i class="bi <?= h($view['urgency_icon']) ?>" aria-hidden="true"></i> <?= h($view['status']) ?></span></div>
                    <p class="replenishment-reason mt-1 mb-2"><?= h($view['reason']) ?></p>
                    <dl class="row small mb-2">
                        <dt class="col-6">Current stock</dt><dd class="col-6 text-end"><?= format_qty((float) $row['live_quantity']) ?></dd>
                        <dt class="col-6">Reorder level</dt><dd class="col-6 text-end"><?= format_qty((float) $row['live_reorder_level']) ?></dd>
                        <dt class="col-6">Expected demand</dt><dd class="col-6 text-end"><?= format_qty((float) $row['expected_demand']) ?></dd>
                        <dt class="col-6">Recommended qty</dt><dd class="col-6 text-end fw-bold"><?= format_qty((float) $row['recommended_quantity']) ?></dd>
                        <dt class="col-6">Estimated cost</dt><dd class="col-6 text-end">₱<?= money($row['estimated_cost']) ?></dd>
                        <dt class="col-6">Stated budget</dt><dd class="col-6 text-end">₱<?= money($row['available_budget']) ?></dd>
                    </dl>
                    <?php if ($view['over_budget']): ?>
                        <div class="small text-danger-emphasis fw-semibold mb-2">Budget check: ₱<?= money($view['budget_diff']) ?> over</div>
                    <?php else: ?>
                        <div class="small text-success-emphasis fw-semibold mb-2">Budget check: within budget</div>
                    <?php endif; ?>
                    <span class="badge <?= h($view['workflow_class']) ?> d-inline-block mb-2">Workflow: <?= h($row['status']) ?></span>
                    <div class="d-flex gap-2 d-print-none">
                        <form method="post" action="index.php?action=update_status" class="flex-grow-1">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                <?php foreach (INTEGRATED_REPLENISHMENT_STATUSES as $statusOption): ?>
                                    <option value="<?= h($statusOption) ?>" <?= $row['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this replenishment analysis to the Recycle Bin?\nIt can be restored later.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="replenishment_id" value="<?= (int) $row['replenishment_id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php render_pagination_controls($pageNum, $perPage, $analysesTotal, $_GET, 'index.php'); ?>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
