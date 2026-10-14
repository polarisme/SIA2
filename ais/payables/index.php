<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_payables';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

function ap_form_data(array $source): array
{
    return [
        'supplier_name' => trim((string) ($source['supplier_name'] ?? '')),
        'invoice_number' => trim((string) ($source['invoice_number'] ?? '')),
        'transaction_id' => trim((string) ($source['transaction_id'] ?? '')),
        'invoice_date' => trim((string) ($source['invoice_date'] ?? date('Y-m-d'))),
        'due_date' => trim((string) ($source['due_date'] ?? date('Y-m-d'))),
        'amount_due' => trim((string) ($source['amount_due'] ?? '')),
        'amount_paid' => trim((string) ($source['amount_paid'] ?? '0')),
        'remarks' => trim((string) ($source['remarks'] ?? '')),
    ];
}

function validate_ap(array $data): array
{
    $errors = [];
    if ($data['supplier_name'] === '' || mb_strlen($data['supplier_name']) > 150) {
        $errors['supplier_name'] = 'Supplier name is required (up to 150 characters).';
    }
    if (mb_strlen($data['invoice_number']) > 100) {
        $errors['invoice_number'] = 'Invoice number must be 100 characters or fewer.';
    }
    if ($data['transaction_id'] !== '' && filter_var($data['transaction_id'], FILTER_VALIDATE_INT) === false) {
        $errors['transaction_id'] = 'Invalid linked transaction.';
    }
    if (!ais_valid_date($data['invoice_date'])) {
        $errors['invoice_date'] = 'Enter a valid invoice date.';
    }
    if (!ais_valid_date($data['due_date'])) {
        $errors['due_date'] = 'Enter a valid due date.';
    }
    if (!isset($errors['invoice_date']) && !isset($errors['due_date']) && $data['due_date'] < $data['invoice_date']) {
        $errors['due_date'] = 'Due date cannot be earlier than the invoice date.';
    }
    $amountDue = ais_parse_amount($data['amount_due']);
    if ($amountDue === false) {
        $errors['amount_due'] = 'Enter a valid amount due greater than zero.';
    }
    $amountPaid = ais_parse_nonnegative_amount($data['amount_paid']);
    if ($amountPaid === false) {
        $errors['amount_paid'] = 'Enter a valid amount paid (0 or more).';
    } elseif ($amountDue !== false && $amountPaid > $amountDue) {
        $errors['amount_paid'] = 'Amount paid cannot exceed the amount due.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS payables database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create' || $action === 'update') {
        $isEditing = $action === 'update';
        $apId = $isEditing ? filter_var($_POST['ap_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($isEditing && ($apId === false || $apId < 1)) {
            set_flash('danger', 'The selected payable could not be found.');
            redirect_to('index.php');
        }
        if ($isEditing) {
            $existsCheck = $pdo->prepare('SELECT 1 FROM accounts_payable WHERE ap_id = ? AND deleted_at IS NULL');
            $existsCheck->execute([$apId]);
            if ($existsCheck->fetchColumn() === false) {
                set_flash('danger', 'The selected payable could not be found.');
                redirect_to('index.php');
            }
        }

        $formData = ap_form_data($_POST);
        $errors = validate_ap($formData);
        if ($formData['transaction_id'] !== '' && !isset($errors['transaction_id'])) {
            $check = $pdo->prepare('SELECT 1 FROM financial_transactions WHERE transaction_id = ? AND deleted_at IS NULL');
            $check->execute([(int) $formData['transaction_id']]);
            if ($check->fetchColumn() === false) {
                $errors['transaction_id'] = 'The selected transaction does not exist.';
            }
        }

        if ($errors === []) {
            $amountDue = ais_parse_amount($formData['amount_due']);
            $amountPaid = ais_parse_nonnegative_amount($formData['amount_paid']);
            $status = ar_ap_effective_status($amountDue, $amountPaid, $formData['due_date']);
            $transactionId = $formData['transaction_id'] !== '' ? (int) $formData['transaction_id'] : null;
            $invoiceNumber = $formData['invoice_number'] !== '' ? $formData['invoice_number'] : null;
            $remarks = $formData['remarks'] !== '' ? $formData['remarks'] : null;

            if ($isEditing) {
                $statement = $pdo->prepare(
                    'UPDATE accounts_payable SET supplier_name = :supplier_name, invoice_number = :invoice_number,
                     transaction_id = :transaction_id, invoice_date = :invoice_date, due_date = :due_date,
                     amount_due = :amount_due, amount_paid = :amount_paid, status = :status, remarks = :remarks
                     WHERE ap_id = :ap_id AND deleted_at IS NULL'
                );
                $statement->execute([
                    'supplier_name' => $formData['supplier_name'], 'invoice_number' => $invoiceNumber,
                    'transaction_id' => $transactionId, 'invoice_date' => $formData['invoice_date'],
                    'due_date' => $formData['due_date'], 'amount_due' => $amountDue, 'amount_paid' => $amountPaid,
                    'status' => $status, 'remarks' => $remarks, 'ap_id' => $apId,
                ]);
                set_flash('success', 'Payable updated.');
                redirect_to('index.php?action=view&id=' . $apId);
            }

            $statement = $pdo->prepare(
                'INSERT INTO accounts_payable
                    (supplier_name, invoice_number, transaction_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
                 VALUES (:supplier_name, :invoice_number, :transaction_id, :invoice_date, :due_date, :amount_due, :amount_paid, :status, :remarks)'
            );
            $statement->execute([
                'supplier_name' => $formData['supplier_name'], 'invoice_number' => $invoiceNumber,
                'transaction_id' => $transactionId, 'invoice_date' => $formData['invoice_date'],
                'due_date' => $formData['due_date'], 'amount_due' => $amountDue, 'amount_paid' => $amountPaid,
                'status' => $status, 'remarks' => $remarks,
            ]);
            set_flash('success', 'Payable added.');
            redirect_to('index.php?action=view&id=' . (int) $pdo->lastInsertId());
        }
        $action = $isEditing ? 'edit' : 'add';
    } elseif ($action === 'record_payment') {
        $apId = filter_var($_POST['ap_id'] ?? null, FILTER_VALIDATE_INT);
        $paymentAmount = ais_parse_amount((string) ($_POST['payment_amount'] ?? ''));
        if ($apId === false || $apId < 1) {
            set_flash('danger', 'The selected payable could not be found.');
            redirect_to('index.php');
        }
        $statement = $pdo->prepare('SELECT amount_due, amount_paid, due_date FROM accounts_payable WHERE ap_id = ? AND deleted_at IS NULL');
        $statement->execute([$apId]);
        $record = $statement->fetch();
        if (!$record) {
            set_flash('danger', 'The selected payable could not be found.');
            redirect_to('index.php');
        }
        $outstanding = round((float) $record['amount_due'] - (float) $record['amount_paid'], 2);
        if ($paymentAmount === false || $paymentAmount > $outstanding) {
            set_flash('danger', 'Enter a valid payment amount that does not exceed the outstanding balance (₱' . money($outstanding) . ').');
            redirect_to('index.php?action=view&id=' . $apId);
        }
        $newAmountPaid = round((float) $record['amount_paid'] + $paymentAmount, 2);
        $newStatus = ar_ap_effective_status((float) $record['amount_due'], $newAmountPaid, $record['due_date']);
        $pdo->prepare('UPDATE accounts_payable SET amount_paid = ?, status = ? WHERE ap_id = ? AND deleted_at IS NULL')
            ->execute([$newAmountPaid, $newStatus, $apId]);
        set_flash('success', 'Payment of ₱' . money($paymentAmount) . ' recorded.');
        redirect_to('index.php?action=view&id=' . $apId);
    } elseif ($action === 'delete') {
        $apId = filter_var($_POST['ap_id'] ?? null, FILTER_VALIDATE_INT);
        if ($apId === false || $apId < 1) {
            set_flash('danger', 'The selected payable could not be found.');
            redirect_to('index.php');
        }
        if (ais_soft_delete($pdo, 'accounts_payable', $apId)) {
            set_flash('success', 'Payable moved to the Recycle Bin.');
        } else {
            set_flash('danger', 'The payable could not be found.');
        }
        redirect_to('index.php');
    }
}

$transactions = [];
if ($pdo instanceof PDO) {
    try {
        $transactions = $pdo->query(
            "SELECT transaction_id, transaction_date, category, amount FROM financial_transactions WHERE transaction_type = 'Expense' AND deleted_at IS NULL ORDER BY transaction_date DESC LIMIT 100"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('AIS payable transaction lookup failed: ' . $exception->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Add / edit form
// ---------------------------------------------------------------------------
if (in_array($action, ['add', 'edit'], true) && $pdo instanceof PDO) {
    $isEditing = $action === 'edit';
    $payable = null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($isEditing) {
            $apId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($apId !== false && $apId >= 1) {
                $statement = $pdo->prepare('SELECT * FROM accounts_payable WHERE ap_id = ? AND deleted_at IS NULL');
                $statement->execute([$apId]);
                $payable = $statement->fetch();
            }
            if (!$payable) {
                set_flash('danger', 'The selected payable could not be found.');
                redirect_to('index.php');
            }
        }
        $formData = ap_form_data($isEditing ? $payable : []);
        $errors = [];
    } else {
        $payable = $isEditing ? ['ap_id' => $apId] : null;
    }

    $pageTitle = $isEditing ? 'Edit Payable' : 'Add Payable';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">ACCOUNTS PAYABLE</div>
        <h1><?= $isEditing ? 'Edit Payable' : 'Add Payable' ?></h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to payables</a>
    </div>
    <section class="content-panel" style="max-width: 760px">
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'update' : 'create' ?><?= $isEditing ? '&id=' . (int) $payable['ap_id'] : '' ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <?php if ($isEditing): ?><input type="hidden" name="ap_id" value="<?= (int) $payable['ap_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="supplier_name">Supplier name <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['supplier_name']) ? ' is-invalid' : '' ?>" id="supplier_name" name="supplier_name" maxlength="150" required value="<?= h($formData['supplier_name']) ?>">
                        <?php if (isset($errors['supplier_name'])): ?><div class="invalid-feedback"><?= h($errors['supplier_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="invoice_number">Invoice number</label>
                        <input class="form-control<?= isset($errors['invoice_number']) ? ' is-invalid' : '' ?>" id="invoice_number" name="invoice_number" maxlength="100" value="<?= h($formData['invoice_number']) ?>">
                        <?php if (isset($errors['invoice_number'])): ?><div class="invalid-feedback"><?= h($errors['invoice_number']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="invoice_date">Invoice date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control<?= isset($errors['invoice_date']) ? ' is-invalid' : '' ?>" id="invoice_date" name="invoice_date" required value="<?= h($formData['invoice_date']) ?>">
                        <?php if (isset($errors['invoice_date'])): ?><div class="invalid-feedback"><?= h($errors['invoice_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="due_date">Due date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control<?= isset($errors['due_date']) ? ' is-invalid' : '' ?>" id="due_date" name="due_date" required value="<?= h($formData['due_date']) ?>">
                        <?php if (isset($errors['due_date'])): ?><div class="invalid-feedback"><?= h($errors['due_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="transaction_id">Related transaction (optional)</label>
                        <select class="form-select<?= isset($errors['transaction_id']) ? ' is-invalid' : '' ?>" id="transaction_id" name="transaction_id">
                            <option value="">None</option>
                            <?php foreach ($transactions as $transaction): ?>
                                <option value="<?= (int) $transaction['transaction_id'] ?>" <?= (string) $formData['transaction_id'] === (string) $transaction['transaction_id'] ? 'selected' : '' ?>>
                                    #<?= (int) $transaction['transaction_id'] ?> — <?= h($transaction['transaction_date']) ?> — <?= h($transaction['category']) ?> (₱<?= money($transaction['amount']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['transaction_id'])): ?><div class="invalid-feedback"><?= h($errors['transaction_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="amount_due">Amount due (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['amount_due']) ? ' is-invalid' : '' ?>" id="amount_due" name="amount_due" min="0.01" step="0.01" required value="<?= h($formData['amount_due']) ?>">
                        <?php if (isset($errors['amount_due'])): ?><div class="invalid-feedback"><?= h($errors['amount_due']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="amount_paid">Amount paid so far (₱)</label>
                        <input type="number" class="form-control<?= isset($errors['amount_paid']) ? ' is-invalid' : '' ?>" id="amount_paid" name="amount_paid" min="0" step="0.01" value="<?= h($formData['amount_paid']) ?>">
                        <?php if (isset($errors['amount_paid'])): ?><div class="invalid-feedback"><?= h($errors['amount_paid']) ?></div><?php endif; ?>
                        <div class="form-text">Balance and status (Unpaid / Partially Paid / Paid / Overdue) are calculated automatically.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($formData['remarks']) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Save payable' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
if ($action === 'view' && $pdo instanceof PDO) {
    $apId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $payable = null;
    if ($apId !== false && $apId >= 1) {
        $statusSql = ar_ap_status_sql();
        $statement = $pdo->prepare("SELECT ap.*, ($statusSql) AS live_status FROM accounts_payable ap WHERE ap_id = ? AND deleted_at IS NULL");
        $statement->execute([$apId]);
        $payable = $statement->fetch();
    }
    if (!$payable) {
        set_flash('danger', 'The selected payable could not be found.');
        redirect_to('index.php');
    }
    $balance = (float) $payable['amount_due'] - (float) $payable['amount_paid'];

    $pageTitle = 'Payable Detail';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">ACCOUNTS PAYABLE</div>
        <h1><?= h($payable['supplier_name']) ?></h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to payables</a>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <section class="content-panel mb-3">
                <div class="panel-heading d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Invoice information</h2>
                    <span class="badge text-bg-<?= h(ar_ap_status_badge($payable['live_status'])) ?>"><?= h($payable['live_status']) ?></span>
                </div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-sm-6"><div class="detail-label">Invoice number</div><div class="detail-value"><?= h($payable['invoice_number'] ?: '—') ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Invoice date</div><div class="detail-value"><?= h($payable['invoice_date']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Due date</div><div class="detail-value"><?= h($payable['due_date']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Related transaction</div><div class="detail-value"><?= $payable['transaction_id'] ? '<a href="' . h(app_url('/ais/transactions/index.php')) . '?action=view&id=' . (int) $payable['transaction_id'] . '">#' . (int) $payable['transaction_id'] . '</a>' : '—' ?></div></div>
                        <div class="col-sm-4"><div class="detail-label">Amount due</div><div class="detail-value">₱<?= money($payable['amount_due']) ?></div></div>
                        <div class="col-sm-4"><div class="detail-label">Amount paid</div><div class="detail-value">₱<?= money($payable['amount_paid']) ?></div></div>
                        <div class="col-sm-4"><div class="detail-label">Balance</div><div class="detail-value fw-bold">₱<?= money($balance) ?></div></div>
                        <div class="col-12"><div class="detail-label">Remarks</div><div class="detail-value"><?= h($payable['remarks'] ?: '—') ?></div></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $payable['ap_id'] ?>">Edit</a>
                        <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this payable to the Recycle Bin?\nIt can be restored later.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="ap_id" value="<?= (int) $payable['ap_id'] ?>">
                            <button class="btn btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="content-panel mb-3">
                <div class="panel-heading"><h2 class="h5 mb-0">Record a payment</h2></div>
                <div class="panel-body">
                    <?php if ($balance <= 0): ?>
                        <p class="text-success mb-0"><i class="bi bi-check-circle" aria-hidden="true"></i> This invoice is fully paid.</p>
                    <?php else: ?>
                        <form method="post" action="index.php?action=record_payment" class="row g-2 align-items-end" onsubmit="return confirm('Record this payment?');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="ap_id" value="<?= (int) $payable['ap_id'] ?>">
                            <div class="col-8">
                                <label class="form-label" for="payment_amount">Payment amount (₱)</label>
                                <input class="form-control" type="number" id="payment_amount" name="payment_amount" min="0.01" max="<?= h(money($balance)) ?>" step="0.01" required>
                            </div>
                            <div class="col-4"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
                        </form>
                        <p class="small text-secondary mt-2 mb-0">Outstanding balance: ₱<?= money($balance) ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, AIS_AR_AP_STATUSES, true)) {
    $statusFilter = '';
}

$conditions = ['deleted_at IS NULL'];
$parameters = [];
if ($search !== '') {
    $conditions[] = '(supplier_name LIKE ? OR invoice_number LIKE ?)';
    $parameters[] = '%' . $search . '%';
    $parameters[] = '%' . $search . '%';
}
$statusSql = ar_ap_status_sql();
if ($statusFilter !== '') {
    $conditions[] = "($statusSql) = ?";
    $parameters[] = $statusFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $conditions);

$records = [];
$summary = ['count' => 0, 'outstanding' => 0.0, 'overdue' => 0.0];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $statement = $pdo->prepare(
            "SELECT *, ($statusSql) AS live_status FROM accounts_payable $whereSql ORDER BY due_date ASC"
        );
        $statement->execute($parameters);
        $records = $statement->fetchAll();

        $summaryRow = $pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount_due - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN ($statusSql) = 'Overdue' THEN amount_due - amount_paid ELSE 0 END), 0) AS overdue
             FROM accounts_payable WHERE (amount_due - amount_paid) > 0 AND deleted_at IS NULL"
        )->fetch();
        $summary = ['count' => (int) $summaryRow['n'], 'outstanding' => (float) $summaryRow['outstanding'], 'overdue' => (float) $summaryRow['overdue']];
    } catch (PDOException $exception) {
        error_log('AIS payables list query failed: ' . $exception->getMessage());
        $databaseError = 'Payables could not be loaded because of a database error.';
    }
}

$pageTitle = 'Accounts Payable';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">ACCOUNTS RECEIVABLE AND PAYABLE</div>
    <h1>Accounts Payable</h1>
    <p>Track supplier invoices and outstanding balances.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Open Invoices</div>
            <div class="stat-value"><?= (int) $summary['count'] ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Total Outstanding</div>
            <div class="stat-value">₱<?= money($summary['outstanding']) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Overdue</div>
            <div class="stat-value text-danger">₱<?= money($summary['overdue']) ?></div>
        </div>
    </div>
</div>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" placeholder="Supplier name or invoice number" value="<?= h($search) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (AIS_AR_AP_STATUSES as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button>
                <a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Payable records</h2>
        <a class="btn btn-primary btn-sm" href="index.php?action=add">Add payable</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Supplier</th><th>Invoice #</th><th>Invoice date</th><th>Due date</th><th class="text-end">Amount due</th><th class="text-end">Amount paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No payable records match the current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= h($record['supplier_name']) ?></td>
                            <td><?= h($record['invoice_number'] ?: '—') ?></td>
                            <td><?= h($record['invoice_date']) ?></td>
                            <td><?= h($record['due_date']) ?></td>
                            <td class="text-end">₱<?= money($record['amount_due']) ?></td>
                            <td class="text-end">₱<?= money($record['amount_paid']) ?></td>
                            <td class="text-end">₱<?= money($record['balance']) ?></td>
                            <td><span class="badge text-bg-<?= h(ar_ap_status_badge($record['live_status'])) ?>"><?= h($record['live_status']) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $record['ap_id'] ?>">View</a>
                                <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['ap_id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
