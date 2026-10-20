<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

const INVENTORY_CATEGORIES = ['Feed', 'Vaccine', 'Medicine', 'Farm Supply', 'Other'];
const INVENTORY_STATUSES = ['Available', 'Low Stock', 'Out of Stock'];

function inventory_form_data(array $source): array
{
    return [
        'item_code' => trim((string) ($source['item_code'] ?? '')),
        'item_name' => trim((string) ($source['item_name'] ?? '')),
        'category' => trim((string) ($source['category'] ?? '')),
        'quantity' => trim((string) ($source['quantity'] ?? '')),
        'unit' => trim((string) ($source['unit'] ?? '')),
        'reorder_level' => trim((string) ($source['reorder_level'] ?? '')),
        'unit_cost' => trim((string) ($source['unit_cost'] ?? '')),
    ];
}

function inventory_decimal_is_valid(string $value): bool
{
    return preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $value) === 1;
}

function validate_inventory_item(array $item): array
{
    $errors = [];

    if ($item['item_code'] === '') {
        $errors['item_code'] = 'Item code is required.';
    } elseif (mb_strlen($item['item_code']) > 50) {
        $errors['item_code'] = 'Item code must be 50 characters or fewer.';
    }

    if ($item['item_name'] === '') {
        $errors['item_name'] = 'Item name is required.';
    } elseif (mb_strlen($item['item_name']) > 150) {
        $errors['item_name'] = 'Item name must be 150 characters or fewer.';
    }

    if (!in_array($item['category'], INVENTORY_CATEGORIES, true)) {
        $errors['category'] = 'Select a valid inventory category.';
    }

    foreach (['quantity' => 'Quantity', 'reorder_level' => 'Reorder level', 'unit_cost' => 'Unit cost'] as $field => $label) {
        if ($item[$field] === '' || !inventory_decimal_is_valid($item[$field])) {
            $errors[$field] = $label . ' must be a non-negative number with up to two decimal places.';
        }
    }

    if ($item['unit'] === '') {
        $errors['unit'] = 'Unit is required.';
    } elseif (mb_strlen($item['unit']) > 30) {
        $errors['unit'] = 'Unit must be 30 characters or fewer.';
    }

    return $errors;
}

function inventory_status(string|float|int $quantity, string|float|int $reorderLevel): string
{
    $quantity = (float) $quantity;
    $reorderLevel = (float) $reorderLevel;

    if ($quantity <= 0) {
        return 'Out of Stock';
    }
    if ($quantity <= $reorderLevel) {
        return 'Low Stock';
    }

    return 'Available';
}

function inventory_status_badge(string $status): string
{
    return match ($status) {
        'Available' => 'success',
        'Low Stock' => 'warning',
        'Out of Stock' => 'danger',
        default => 'secondary',
    };
}

function inventory_status_sql(): string
{
    return "CASE
        WHEN quantity <= 0 THEN 'Out of Stock'
        WHEN quantity <= reorder_level THEN 'Low Stock'
        ELSE 'Available'
    END";
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Inventory database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
$activeNavigation = 'inventory';
$canManage = can_manage_flocks();
$errors = [];
$formData = inventory_form_data([]);
$item = null;
$stockHistory = [];
$isEditing = false;
$pageTitle = 'Inventory Dashboard';
$stats = ['total_items' => 0, 'available_items' => 0, 'low_stock_items' => 0, 'out_of_stock_items' => 0, 'total_value' => 0];
$records = [];
$batchSearch = '';
$nameSearch = '';
$categoryFilter = '';
$statusFilter = '';
$databaseFailure = $databaseError !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && !$databaseFailure) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if (!$canManage) {
        set_flash('danger', 'You are not authorized to change inventory records.');
        redirect_to('index.php');
    }

    try {
        if ($action === 'create' || $action === 'update') {
            $formData = inventory_form_data($_POST);
            $errors = validate_inventory_item($formData);
            $isEditing = $action === 'update';
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);

            if ($isEditing && ($itemId === false || $itemId < 1)) {
                set_flash('danger', 'The selected inventory item could not be found.');
                redirect_to('index.php');
            }

            if ($isEditing) {
                $exists = $pdo->prepare('SELECT item_id FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL');
                $exists->execute([$itemId]);
                if ($exists->fetchColumn() === false) {
                    set_flash('danger', 'The selected inventory item could not be found.');
                    redirect_to('index.php');
                }
            }

            if ($errors === []) {
                $duplicate = $pdo->prepare(
                    'SELECT item_id FROM poultry_inventory
                     WHERE item_code = :item_code AND deleted_at IS NULL AND (:item_id IS NULL OR item_id <> :excluded_item_id)'
                );
                $duplicate->execute([
                    'item_code' => $formData['item_code'],
                    'item_id' => $isEditing ? $itemId : null,
                    'excluded_item_id' => $isEditing ? $itemId : 0,
                ]);
                if ($duplicate->fetchColumn() !== false) {
                    $errors['item_code'] = 'That item code is already in use.';
                }
            }

            if ($errors === []) {
                $status = inventory_status($formData['quantity'], $formData['reorder_level']);
                try {
                    if ($isEditing) {
                        $statement = $pdo->prepare(
                            'UPDATE poultry_inventory
                             SET item_code = :item_code, item_name = :item_name, category = :category,
                                 quantity = :quantity, unit = :unit, reorder_level = :reorder_level,
                                 unit_cost = :unit_cost, status = :status
                             WHERE item_id = :item_id AND deleted_at IS NULL'
                        );
                        $statement->execute($formData + ['status' => $status, 'item_id' => $itemId]);
                        audit_pms_action($pdo, 'UPDATE', 'poultry_inventory', $itemId);
                        set_flash('success', 'Inventory item updated.');
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO poultry_inventory
                                (item_code, item_name, category, quantity, unit, reorder_level, unit_cost, status)
                             VALUES
                                (:item_code, :item_name, :category, :quantity, :unit, :reorder_level, :unit_cost, :status)'
                        );
                        $statement->execute($formData + ['status' => $status]);
                        audit_pms_action($pdo, 'CREATE', 'poultry_inventory', (int) $pdo->lastInsertId());
                        set_flash('success', 'Inventory item added.');
                    }

                    redirect_to($isEditing ? 'index.php?action=view&id=' . $itemId : 'index.php');
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        $errors['item_code'] = 'That item code is already in use.';
                    } else {
                        throw $exception;
                    }
                }
            }

            $action = $isEditing ? 'edit' : 'add';
            $pageTitle = $isEditing ? 'Edit Inventory Item' : 'Add Inventory Item';
            if ($isEditing) {
                $item = ['item_id' => $itemId] + $formData;
            }
        } elseif ($action === 'delete') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) {
                set_flash('danger', 'The selected inventory item could not be found.');
                redirect_to('index.php');
            }
            $identity = $pdo->prepare('SELECT item_code FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL');
            $identity->execute([$itemId]);
            $itemCode = $identity->fetchColumn();
            if ($itemCode === false) {
                set_flash('danger', 'The selected active inventory item could not be found.');
                redirect_to('index.php');
            }
            try {
                if (!pms_soft_delete($pdo, 'poultry_inventory', $itemId)) {
                    set_flash('danger', 'The inventory item could not be moved to the Recycle Bin.');
                } else {
                    set_flash('success', 'Inventory item ' . $itemCode . ' moved to the Recycle Bin.');
                }
            } catch (RuntimeException $exception) {
                set_flash('danger', $exception->getMessage());
            }
            redirect_to('index.php');
        } elseif ($action === 'adjust') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $direction = (string) ($_POST['direction'] ?? '');
            $adjustment = trim((string) ($_POST['adjustment'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($itemId === false || $itemId < 1) {
                set_flash('danger', 'The selected inventory item could not be found.');
                redirect_to('index.php');
            }
            if (!in_array($direction, ['add', 'reduce'], true)) {
                set_flash('danger', 'Choose whether to add or reduce stock.');
                redirect_to('index.php?action=view&id=' . $itemId);
            }
            if (!inventory_decimal_is_valid($adjustment) || (float) $adjustment <= 0) {
                set_flash('danger', 'Enter an adjustment greater than zero, with up to two decimal places.');
                redirect_to('index.php?action=view&id=' . $itemId);
            }
            if ($reason === '' || mb_strlen($reason) > 255) {
                set_flash('danger', 'Enter a reason of 1 to 255 characters for this adjustment.');
                redirect_to('index.php?action=view&id=' . $itemId);
            }

            $pdo->beginTransaction();
            try {
                $adjustmentId = pms_apply_inventory_adjustment(
                    $pdo, $itemId, $direction === 'add' ? 'Add' : 'Reduce', $adjustment, $reason, 'Manual', null
                );
                $pdo->commit();
            } catch (RuntimeException $exception) {
                $pdo->rollBack();
                set_flash('danger', 'The reduction exceeds the available stock. Quantity was not changed.');
                redirect_to('index.php?action=view&id=' . $itemId);
            }

            audit_pms_action($pdo, 'UPDATE', 'poultry_inventory', $itemId);
            audit_pms_action($pdo, 'CREATE', 'inventory_adjustments', $adjustmentId);
            $directionLabel = $direction === 'add' ? 'added to' : 'reduced from';
            set_flash('success', 'Stock ' . $directionLabel . ' the item and recorded in its stock history.');
            redirect_to('index.php?action=view&id=' . $itemId);
        } else {
            set_flash('danger', 'The requested action is not available.');
            redirect_to('index.php');
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Inventory write failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'The inventory change could not be saved because of a database error.';
    }
}

if ($pdo instanceof PDO && !$databaseFailure && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        if (in_array($action, ['add', 'edit'], true)) {
            if (!$canManage) {
                set_flash('warning', 'Sign in as an administrator to add or edit inventory.');
                redirect_to('index.php');
            }

            $isEditing = $action === 'edit';
            $pageTitle = $isEditing ? 'Edit Inventory Item' : 'Add Inventory Item';
            if ($isEditing) {
                $itemId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                if ($itemId === false || $itemId < 1) {
                    set_flash('danger', 'The selected inventory item could not be found.');
                    redirect_to('index.php');
                }

                $statement = $pdo->prepare('SELECT * FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL');
                $statement->execute([$itemId]);
                $item = $statement->fetch();
                if (!$item) {
                    set_flash('danger', 'The selected inventory item could not be found.');
                    redirect_to('index.php');
                }
                $formData = inventory_form_data($item);
            }
        } elseif ($action === 'view') {
            $itemId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) {
                set_flash('danger', 'The selected inventory item could not be found.');
                redirect_to('index.php');
            }

            $statusSql = inventory_status_sql();
            $statement = $pdo->prepare(
                "SELECT *, ($statusSql) AS calculated_status, (quantity * unit_cost) AS item_value
                 FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL"
            );
            $statement->execute([$itemId]);
            $item = $statement->fetch();
            if (!$item) {
                set_flash('danger', 'The selected inventory item could not be found.');
                redirect_to('index.php');
            }
            $pageTitle = 'Inventory ' . $item['item_code'];

            $historyStatement = $pdo->prepare(
                'SELECT * FROM inventory_adjustments WHERE item_id = ? ORDER BY adjusted_at DESC, adjustment_id DESC LIMIT 50'
            );
            $historyStatement->execute([$itemId]);
            $stockHistory = $historyStatement->fetchAll();
        } else {
            $action = 'list';
            $statusSql = inventory_status_sql();
            $summary = $pdo->query(
                "SELECT
                    COUNT(*) AS total_items,
                    COALESCE(SUM(CASE WHEN quantity > reorder_level AND quantity > 0 THEN 1 ELSE 0 END), 0) AS available_items,
                    COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END), 0) AS low_stock_items,
                    COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_items,
                    COALESCE(SUM(quantity * unit_cost), 0) AS total_value
                 FROM poultry_inventory WHERE deleted_at IS NULL"
            )->fetch();
            if ($summary) {
                $stats = $summary;
            }

            $conditions = ['deleted_at IS NULL'];
            $parameters = [];
            $batchSearch = trim((string) ($_GET['item_code'] ?? ''));
            $nameSearch = trim((string) ($_GET['item_name'] ?? ''));
            $categoryFilter = trim((string) ($_GET['category'] ?? ''));
            $statusFilter = trim((string) ($_GET['status'] ?? ''));

            if ($batchSearch !== '') {
                $conditions[] = 'item_code LIKE :item_code';
                $parameters['item_code'] = '%' . $batchSearch . '%';
            }
            if ($nameSearch !== '') {
                $conditions[] = 'item_name LIKE :item_name';
                $parameters['item_name'] = '%' . $nameSearch . '%';
            }
            if (in_array($categoryFilter, INVENTORY_CATEGORIES, true)) {
                $conditions[] = 'category = :category';
                $parameters['category'] = $categoryFilter;
            } else {
                $categoryFilter = '';
            }
            if (in_array($statusFilter, INVENTORY_STATUSES, true)) {
                $conditions[] = '(' . $statusSql . ') = :status';
                $parameters['status'] = $statusFilter;
            } else {
                $statusFilter = '';
            }

            $whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

            $countStatement = $pdo->prepare('SELECT COUNT(*) FROM poultry_inventory' . $whereSql);
            $countStatement->execute($parameters);
            $totalRecords = (int) $countStatement->fetchColumn();

            ['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();

            $sql = "SELECT item_id, item_code, item_name, category, quantity, unit,
                           reorder_level, unit_cost, ($statusSql) AS calculated_status,
                           (quantity * unit_cost) AS item_value, created_at
                    FROM poultry_inventory" . $whereSql . '
                    ORDER BY item_name ASC, item_id DESC
                    LIMIT ' . $perPage . ' OFFSET ' . $offset;
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $records = $statement->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Inventory query failed: ' . $exception->getMessage());
        $databaseFailure = true;
        $databaseError = 'Inventory information could not be loaded. Check the database connection and schema.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>

<?php if ($databaseError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div>
<?php endif; ?>

<?php if (!$canManage && !$databaseFailure): ?>
    <div class="alert alert-warning role-note" role="status">
        Read-only access. <a href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in as an administrator</a> to add, edit, or adjust stock.
    </div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1><?= $isEditing ? 'Edit inventory item' : 'Add inventory item' ?></h1>
            <p><?= $isEditing ? 'Update the selected item details and stock levels.' : 'Enter the details for a feed, vaccine, medicine, supply, or other item.' ?></p>
        </div>
        <a class="btn btn-outline-secondary" href="index.php">Back to inventory</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert"><strong>Please correct the highlighted fields.</strong></div>
    <?php endif; ?>

    <section class="content-panel">
        <div class="panel-heading"><h2 class="h5 mb-0">Item information</h2></div>
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'edit' : 'add' ?><?= $isEditing ? '&id=' . (int) $item['item_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="item_code">Item code <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['item_code']) ? ' is-invalid' : '' ?>" id="item_code" name="item_code" maxlength="50" required value="<?= h($formData['item_code']) ?>">
                        <?php if (isset($errors['item_code'])): ?><div class="invalid-feedback"><?= h($errors['item_code']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="item_name">Item name <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['item_name']) ? ' is-invalid' : '' ?>" id="item_name" name="item_name" maxlength="150" required value="<?= h($formData['item_name']) ?>">
                        <?php if (isset($errors['item_name'])): ?><div class="invalid-feedback"><?= h($errors['item_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['category']) ? ' is-invalid' : '' ?>" id="category" name="category" required>
                            <option value="">Select category</option>
                            <?php foreach (INVENTORY_CATEGORIES as $category): ?>
                                <option value="<?= h($category) ?>" <?= $formData['category'] === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?= h($errors['category']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="unit">Unit <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['unit']) ? ' is-invalid' : '' ?>" id="unit" name="unit" maxlength="30" required placeholder="e.g. kg, bag, bottle" value="<?= h($formData['unit']) ?>">
                        <?php if (isset($errors['unit'])): ?><div class="invalid-feedback"><?= h($errors['unit']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="quantity">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['quantity']) ? ' is-invalid' : '' ?>" id="quantity" name="quantity" min="0" max="9999999999.99" step="0.01" required value="<?= h($formData['quantity']) ?>">
                        <?php if (isset($errors['quantity'])): ?><div class="invalid-feedback"><?= h($errors['quantity']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reorder_level">Reorder level <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['reorder_level']) ? ' is-invalid' : '' ?>" id="reorder_level" name="reorder_level" min="0" max="9999999999.99" step="0.01" required value="<?= h($formData['reorder_level']) ?>">
                        <?php if (isset($errors['reorder_level'])): ?><div class="invalid-feedback"><?= h($errors['reorder_level']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="unit_cost">Unit cost <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['unit_cost']) ? ' is-invalid' : '' ?>" id="unit_cost" name="unit_cost" min="0" max="9999999999.99" step="0.01" required value="<?= h($formData['unit_cost']) ?>">
                        <?php if (isset($errors['unit_cost'])): ?><div class="invalid-feedback"><?= h($errors['unit_cost']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Add inventory item' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
            <p class="small text-secondary mt-3 mb-0">Stock status is calculated automatically from quantity and reorder level.</p>
        </div>
    </section>

<?php elseif ($action === 'view' && $item !== null): ?>
    <?php $itemStatus = $item['calculated_status']; ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">INVENTORY ITEM #<?= (int) $item['item_id'] ?></div>
            <h1><?= h($item['item_name']) ?></h1>
            <p><?= h($item['item_code']) ?> · <?= h($item['category']) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="index.php">Back to inventory</a>
            <?php if ($canManage): ?>
                <a class="btn btn-primary" href="index.php?action=edit&amp;id=<?= (int) $item['item_id'] ?>">Edit item</a>
                <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this inventory item to the Recycle Bin?\nItem Code: <?= h($item['item_code']) ?>\nThis item can be restored later.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Move to Recycle Bin</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <section class="content-panel">
        <div class="panel-heading d-flex justify-content-between align-items-center gap-3">
            <h2 class="h5 mb-0">Inventory details</h2>
            <span class="badge text-bg-<?= h(inventory_status_badge($itemStatus)) ?>"><?= h($itemStatus) ?></span>
        </div>
        <div class="panel-body">
            <div class="row g-4">
                <?php
                $details = [
                    'Item ID' => $item['item_id'],
                    'Item code' => $item['item_code'],
                    'Item name' => $item['item_name'],
                    'Category' => $item['category'],
                    'Quantity' => number_format((float) $item['quantity'], 2) . ' ' . $item['unit'],
                    'Reorder level' => number_format((float) $item['reorder_level'], 2) . ' ' . $item['unit'],
                    'Unit cost' => number_format((float) $item['unit_cost'], 2),
                    'Estimated item value' => number_format((float) $item['item_value'], 2),
                    'Created date' => $item['created_at'],
                ];
                ?>
                <?php foreach ($details as $label => $value): ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="detail-label"><?= h($label) ?></div>
                        <div class="detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <section class="content-panel mt-4">
            <div class="panel-heading">
                <h2 class="h5 mb-1">Adjust stock</h2>
                <p class="small text-secondary mb-0">Changes the current quantity and records the adjustment, with its reason, in the stock history below.</p>
            </div>
            <div class="panel-body">
                <form method="post" action="index.php?action=adjust" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="adjust">
                    <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="direction">Adjustment</label>
                        <select class="form-select" id="direction" name="direction" required>
                            <option value="add">Add stock</option>
                            <option value="reduce">Reduce stock</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="adjustment">Quantity</label>
                        <input class="form-control" type="number" id="adjustment" name="adjustment" min="0.01" max="9999999999.99" step="0.01" required>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="reason">Reason <span class="text-danger">*</span></label>
                        <input class="form-control" id="reason" name="reason" maxlength="255" required placeholder="e.g. New delivery or damaged stock">
                    </div>
                    <div class="col-lg-2"><button class="btn btn-outline-primary w-100" type="submit">Apply adjustment</button></div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <section class="content-panel mt-4">
        <div class="panel-heading"><h2 class="h5 mb-0">Stock history</h2></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Date</th><th>Direction</th><th>Quantity</th><th>Before</th><th>After</th><th>Reason</th><th>Source</th><th>Adjusted by</th></tr></thead>
                <tbody>
                    <?php if ($stockHistory === []): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-4">No stock adjustments recorded for this item yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stockHistory as $entry): ?>
                            <tr>
                                <td class="text-nowrap"><?= h((string) $entry['adjusted_at']) ?></td>
                                <td><?= h($entry['direction']) ?></td>
                                <td><?= number_format((float) $entry['quantity'], 2) ?> <?= h($item['unit']) ?></td>
                                <td><?= number_format((float) $entry['quantity_before'], 2) ?></td>
                                <td><?= number_format((float) $entry['quantity_after'], 2) ?></td>
                                <td><?= h($entry['reason']) ?></td>
                                <td><?= h($entry['source']) ?></td>
                                <td><?= h($entry['adjusted_by'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <div class="page-heading d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <div class="eyebrow">POULTRY MANAGEMENT</div>
            <h1>Inventory dashboard</h1>
            <p>Monitor stock levels and estimated inventory value.</p>
        </div>
        <?php if ($canManage && !$databaseFailure): ?><a class="btn btn-primary" href="index.php?action=add">Add inventory item</a><?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['Total inventory items', $stats['total_items']],
            ['Available items', $stats['available_items']],
            ['Low-stock items', $stats['low_stock_items']],
            ['Out-of-stock items', $stats['out_of_stock_items']],
            ['Total inventory value', number_format((float) $stats['total_value'], 2)],
        ];
        ?>
        <?php foreach ($statCards as [$label, $value]): ?>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-label"><?= h($label) ?></div>
                    <div class="stat-value"><?= h($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="content-panel">
        <div class="panel-heading">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2 class="h5 mb-0">Inventory items</h2>
                <span class="text-secondary small"><?= number_format($totalRecords) ?> record<?= $totalRecords === 1 ? '' : 's' ?></span>
            </div>
        </div>
        <div class="panel-body border-bottom">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <?php pagination_preserve_per_page_field($perPage); ?>
                <div class="col-md-3">
                    <label class="form-label" for="code_search">Item code</label>
                    <input class="form-control" id="code_search" name="item_code" value="<?= h($batchSearch) ?>" placeholder="Search item code">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="name_search">Item name</label>
                    <input class="form-control" id="name_search" name="item_name" value="<?= h($nameSearch) ?>" placeholder="Search item name">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="category_filter">Category</label>
                    <select class="form-select" id="category_filter" name="category">
                        <option value="">All categories</option>
                        <?php foreach (INVENTORY_CATEGORIES as $category): ?>
                            <option value="<?= h($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="status_filter">Stock status</label>
                    <select class="form-select" id="status_filter" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (INVENTORY_STATUSES as $status): ?>
                            <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Search</button>
                    <a class="btn btn-outline-secondary" href="index.php" aria-label="Clear filters" title="Clear filters">Clear</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 12%"><col style="width: 22%"><col style="width: 12%"><col style="width: 10%">
                    <col style="width: 8%"><col style="width: 10%"><col style="width: 12%"><col style="width: 14%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Item code</th>
                        <th scope="col">Item name</th>
                        <th scope="col">Category</th>
                        <th scope="col" class="text-end">Quantity</th>
                        <th scope="col">Unit</th>
                        <th scope="col" class="text-end">Reorder level</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-5">No inventory items match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td class="fw-semibold"><span class="cell-id" title="<?= h($record['item_code']) ?>"><?= h($record['item_code']) ?></span></td>
                                <td class="cell-wrap"><?= h($record['item_name']) ?></td>
                                <td class="cell-wrap"><?= h($record['category']) ?></td>
                                <td class="text-end"><?= number_format((float) $record['quantity'], 2) ?></td>
                                <td class="cell-wrap"><?= h($record['unit']) ?></td>
                                <td class="text-end"><?= number_format((float) $record['reorder_level'], 2) ?></td>
                                <td><span class="badge text-bg-<?= h(inventory_status_badge($record['calculated_status'])) ?>"><?= h($record['calculated_status']) ?></span></td>
                                <td class="text-end table-detail-toggle">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#inventory-detail-<?= (int) $record['item_id'] ?>" aria-expanded="false" aria-label="Show more detail for item <?= h($record['item_code']) ?>">
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </button>
                                        <a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $record['item_id'] ?>">View</a>
                                        <?php if ($canManage): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['item_id'] ?>">Edit</a>
                                            <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this inventory item to the Recycle Bin?\nItem Code: <?= h($record['item_code']) ?>\nThis item can be restored later.');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="item_id" value="<?= (int) $record['item_id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Move item <?= h($record['item_code']) ?> to Recycle Bin">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr class="collapse table-detail-row" id="inventory-detail-<?= (int) $record['item_id'] ?>">
                                <td colspan="8">
                                    <div class="table-detail-body row g-3">
                                        <div class="col-sm-4"><div class="detail-label">Unit Cost</div><div class="detail-value"><?= number_format((float) $record['unit_cost'], 2) ?></div></div>
                                        <div class="col-sm-4"><div class="detail-label">Estimated Value</div><div class="detail-value"><?= number_format((float) $record['item_value'], 2) ?></div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination_controls($pageNum, $perPage, $totalRecords, $_GET, 'index.php'); ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>