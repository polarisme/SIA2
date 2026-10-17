<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

function transaction_form_data(array $source): array
{
    return [
        'transaction_date' => trim((string) ($source['transaction_date'] ?? date('Y-m-d'))),
        'transaction_type' => trim((string) ($source['transaction_type'] ?? 'Income')),
        'category' => trim((string) ($source['category'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'amount' => trim((string) ($source['amount'] ?? '')),
        'flock_id' => trim((string) ($source['flock_id'] ?? '')),
        'payment_method' => trim((string) ($source['payment_method'] ?? 'Cash')),
        'reference_number' => trim((string) ($source['reference_number'] ?? '')),
        'status' => trim((string) ($source['status'] ?? 'Recorded')),
    ];
}

function validate_transaction(array $data): array
{
    $errors = [];
    if (!ais_valid_date($data['transaction_date'])) {
        $errors['transaction_date'] = 'Enter a valid transaction date.';
    }
    if (!in_array($data['transaction_type'], AIS_TRANSACTION_TYPES, true)) {
        $errors['transaction_type'] = 'Select Income or Expense.';
    }
    if ($data['category'] === '' || mb_strlen($data['category']) > 100) {
        $errors['category'] = 'Category is required (up to 100 characters).';
    }
    if (mb_strlen($data['description']) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }
    if (ais_parse_amount($data['amount']) === false) {
        $errors['amount'] = 'Enter a valid amount greater than zero (up to 2 decimal places).';
    }
    if ($data['flock_id'] !== '' && filter_var($data['flock_id'], FILTER_VALIDATE_INT) === false) {
        $errors['flock_id'] = 'Invalid flock selection.';
    }
    if (!in_array($data['payment_method'], AIS_PAYMENT_METHODS, true)) {
        $errors['payment_method'] = 'Select a valid payment method.';
    }
    if (mb_strlen($data['reference_number']) > 100) {
        $errors['reference_number'] = 'Reference number must be 100 characters or fewer.';
    }
    if (!in_array($data['status'], AIS_TRANSACTION_STATUSES, true)) {
        $errors['status'] = 'Select a valid status.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS transactions database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
$canManage = is_authenticated();

// ---------------------------------------------------------------------------
// Write actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'create' || $action === 'update') {
        $isEditing = $action === 'update';
        $transactionId = $isEditing ? filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($isEditing && ($transactionId === false || $transactionId < 1)) {
            set_flash('danger', 'The selected transaction could not be found.');
            redirect_to('index.php');
        }
        if ($isEditing) {
            $existsCheck = $pdo->prepare('SELECT 1 FROM financial_transactions WHERE transaction_id = ? AND deleted_at IS NULL');
            $existsCheck->execute([$transactionId]);
            if ($existsCheck->fetchColumn() === false) {
                set_flash('danger', 'The selected transaction could not be found.');
                redirect_to('index.php');
            }
        }

        $formData = transaction_form_data($_POST);
        $errors = validate_transaction($formData);
        if ($formData['flock_id'] !== '' && !isset($errors['flock_id'])) {
            $check = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $check->execute([(int) $formData['flock_id']]);
            if ($check->fetchColumn() === false) {
                $errors['flock_id'] = 'The selected flock does not exist.';
            }
        }

        if ($errors === []) {
            $amount = ais_parse_amount($formData['amount']);
            $flockId = $formData['flock_id'] !== '' ? (int) $formData['flock_id'] : null;
            $referenceNumber = $formData['reference_number'] !== '' ? $formData['reference_number'] : null;
            $description = $formData['description'] !== '' ? $formData['description'] : null;

            if ($isEditing) {
                $statement = $pdo->prepare(
                    'UPDATE financial_transactions SET transaction_date = :transaction_date, transaction_type = :transaction_type,
                     category = :category, description = :description, amount = :amount, flock_id = :flock_id,
                     payment_method = :payment_method, reference_number = :reference_number, status = :status
                     WHERE transaction_id = :transaction_id AND deleted_at IS NULL'
                );
                $statement->execute([
                    'transaction_date' => $formData['transaction_date'],
                    'transaction_type' => $formData['transaction_type'],
                    'category' => $formData['category'],
                    'description' => $description,
                    'amount' => $amount,
                    'flock_id' => $flockId,
                    'payment_method' => $formData['payment_method'],
                    'reference_number' => $referenceNumber,
                    'status' => $formData['status'],
                    'transaction_id' => $transactionId,
                ]);
                audit_pms_action($pdo, 'UPDATE', 'financial_transactions', $transactionId);
                set_flash('success', 'Transaction updated.');
                redirect_to('index.php?action=view&id=' . $transactionId);
            }

            $statement = $pdo->prepare(
                'INSERT INTO financial_transactions
                    (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, reference_number, status)
                 VALUES (:transaction_date, :transaction_type, :category, :description, :amount, :flock_id, :payment_method, :reference_number, :status)'
            );
            $statement->execute([
                'transaction_date' => $formData['transaction_date'],
                'transaction_type' => $formData['transaction_type'],
                'category' => $formData['category'],
                'description' => $description,
                'amount' => $amount,
                'flock_id' => $flockId,
                'payment_method' => $formData['payment_method'],
                'reference_number' => $referenceNumber,
                'status' => $formData['status'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            audit_pms_action($pdo, 'CREATE', 'financial_transactions', $newId);
            set_flash('success', 'Transaction recorded.');
            redirect_to('index.php?action=view&id=' . $newId);
        }
        // Validation failed: fall through to the add/edit form below with $errors/$formData set.
        $action = $isEditing ? 'edit' : 'add';
    } elseif ($action === 'delete') {
        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        if ($transactionId === false || $transactionId < 1) {
            set_flash('danger', 'The selected transaction could not be found.');
            redirect_to('index.php');
        }
        try {
            if (ais_soft_delete($pdo, 'financial_transactions', $transactionId)) {
                set_flash('success', 'Transaction moved to the Recycle Bin.');
            } else {
                set_flash('danger', 'The transaction could not be found.');
            }
        } catch (PDOException $exception) {
            error_log('AIS transaction delete failed: ' . $exception->getMessage());
            set_flash('danger', 'The transaction could not be deleted.');
        }
        redirect_to('index.php');
    } elseif ($action === 'upload_document') {
        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        $documentType = (string) ($_POST['document_type'] ?? '');
        if ($transactionId === false || $transactionId < 1) {
            set_flash('danger', 'The selected transaction could not be found.');
            redirect_to('index.php');
        }
        if (!in_array($documentType, AIS_DOCUMENT_TYPES, true)) {
            set_flash('danger', 'Select a valid document type.');
            redirect_to('index.php?action=view&id=' . $transactionId);
        }
        $exists = $pdo->prepare('SELECT 1 FROM financial_transactions WHERE transaction_id = ? AND deleted_at IS NULL');
        $exists->execute([$transactionId]);
        if ($exists->fetchColumn() === false) {
            set_flash('danger', 'The transaction could not be found.');
            redirect_to('index.php');
        }
        $upload = ais_store_uploaded_document($_FILES['document'] ?? []);
        if ($upload['error'] !== null) {
            set_flash('danger', $upload['error']);
        } elseif (!$upload['ok']) {
            set_flash('warning', 'Choose a file to upload.');
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO transaction_documents (transaction_id, document_type, file_name, file_path) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$transactionId, $documentType, $upload['file_name'], $upload['file_path']]);
            audit_pms_action($pdo, 'CREATE', 'transaction_documents', (int) $pdo->lastInsertId());
            set_flash('success', 'Supporting document uploaded.');
        }
        redirect_to('index.php?action=view&id=' . $transactionId);
    } elseif ($action === 'delete_document') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        if ($documentId === false || $documentId < 1 || $transactionId === false || $transactionId < 1) {
            set_flash('danger', 'The selected document could not be found.');
            redirect_to('index.php');
        }
        if (ais_soft_delete($pdo, 'transaction_documents', $documentId)) {
            set_flash('success', 'Document moved to the Recycle Bin.');
        } else {
            set_flash('danger', 'The document could not be found.');
        }
        redirect_to('index.php?action=view&id=' . $transactionId);
    }
}

$flocks = [];
if ($pdo instanceof PDO) {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code')->fetchAll();
    } catch (PDOException $exception) {
        error_log('AIS transaction flock lookup failed: ' . $exception->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Add / edit form
// ---------------------------------------------------------------------------
if (in_array($action, ['add', 'edit'], true) && $pdo instanceof PDO) {
    $isEditing = $action === 'edit';
    $transaction = null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Fresh GET request: load the existing record (edit) or start blank (add).
        if ($isEditing) {
            $transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($transactionId !== false && $transactionId >= 1) {
                $statement = $pdo->prepare('SELECT * FROM financial_transactions WHERE transaction_id = ? AND deleted_at IS NULL');
                $statement->execute([$transactionId]);
                $transaction = $statement->fetch();
            }
            if ($transaction === false || $transaction === null) {
                set_flash('danger', 'The selected transaction could not be found.');
                redirect_to('index.php');
            }
        }
        $formData = transaction_form_data($isEditing ? $transaction : ['transaction_type' => (string) ($_GET['type'] ?? 'Income')]);
        $errors = [];
    } else {
        // Failed POST validation: $formData/$errors/$transactionId were already set above;
        // keep them so the user's entered values are redisplayed instead of being lost.
        $transaction = $isEditing ? ['transaction_id' => $transactionId] : null;
    }

    $categorySuggestions = array_unique(array_merge(
        ais_category_suggestions($pdo, 'Income'),
        ais_category_suggestions($pdo, 'Expense')
    ));

    $activeNavigation = $formData['transaction_type'] === 'Expense' ? 'ais_transactions_expense' : 'ais_transactions_income';
    $pageTitle = $isEditing ? 'Edit Transaction' : 'Record Transaction';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">TRANSACTIONS</div>
        <h1><?= $isEditing ? 'Edit Transaction' : 'Record Transaction' ?></h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to transactions</a>
    </div>
    <section class="content-panel" style="max-width: 780px">
        <div class="panel-body">
            <form method="post" action="index.php?action=<?= $isEditing ? 'update' : 'create' ?><?= $isEditing ? '&id=' . (int) $transaction['transaction_id'] : '' ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <?php if ($isEditing): ?><input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="transaction_type">Type <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['transaction_type']) ? ' is-invalid' : '' ?>" id="transaction_type" name="transaction_type" required>
                            <?php foreach (AIS_TRANSACTION_TYPES as $type): ?>
                                <option value="<?= h($type) ?>" <?= $formData['transaction_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['transaction_type'])): ?><div class="invalid-feedback"><?= h($errors['transaction_type']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="transaction_date">Transaction date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control<?= isset($errors['transaction_date']) ? ' is-invalid' : '' ?>" id="transaction_date" name="transaction_date" required value="<?= h($formData['transaction_date']) ?>">
                        <?php if (isset($errors['transaction_date'])): ?><div class="invalid-feedback"><?= h($errors['transaction_date']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="amount">Amount (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control<?= isset($errors['amount']) ? ' is-invalid' : '' ?>" id="amount" name="amount" min="0.01" step="0.01" required value="<?= h($formData['amount']) ?>">
                        <?php if (isset($errors['amount'])): ?><div class="invalid-feedback"><?= h($errors['amount']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['category']) ? ' is-invalid' : '' ?>" id="category" name="category" list="category-suggestions" maxlength="100" required value="<?= h($formData['category']) ?>">
                        <datalist id="category-suggestions">
                            <?php foreach ($categorySuggestions as $suggestion): ?><option value="<?= h($suggestion) ?>"><?php endforeach; ?>
                        </datalist>
                        <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?= h($errors['category']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="flock_id">Related flock / batch (optional)</label>
                        <select class="form-select<?= isset($errors['flock_id']) ? ' is-invalid' : '' ?>" id="flock_id" name="flock_id">
                            <option value="">None</option>
                            <?php foreach ($flocks as $flock): ?>
                                <option value="<?= (int) $flock['flock_id'] ?>" <?= (string) $formData['flock_id'] === (string) $flock['flock_id'] ? 'selected' : '' ?>><?= h($flock['batch_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['flock_id'])): ?><div class="invalid-feedback"><?= h($errors['flock_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="payment_method">Payment method <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['payment_method']) ? ' is-invalid' : '' ?>" id="payment_method" name="payment_method" required>
                            <?php foreach (AIS_PAYMENT_METHODS as $method): ?>
                                <option value="<?= h($method) ?>" <?= $formData['payment_method'] === $method ? 'selected' : '' ?>><?= h($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['payment_method'])): ?><div class="invalid-feedback"><?= h($errors['payment_method']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="reference_number">Reference number</label>
                        <input class="form-control<?= isset($errors['reference_number']) ? ' is-invalid' : '' ?>" id="reference_number" name="reference_number" maxlength="100" value="<?= h($formData['reference_number']) ?>">
                        <?php if (isset($errors['reference_number'])): ?><div class="invalid-feedback"><?= h($errors['reference_number']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>" id="status" name="status" required>
                            <?php foreach (AIS_TRANSACTION_STATUSES as $status): ?>
                                <option value="<?= h($status) ?>" <?= $formData['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?= h($errors['status']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <input class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" maxlength="255" value="<?= h($formData['description']) ?>">
                        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save changes' : 'Save transaction' ?></button>
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
// View (detail + documents)
// ---------------------------------------------------------------------------
if ($action === 'view' && $pdo instanceof PDO) {
    $transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $transaction = null;
    if ($transactionId !== false && $transactionId >= 1) {
        $statement = $pdo->prepare(
            'SELECT t.*, f.batch_code FROM financial_transactions t LEFT JOIN flocks f ON f.flock_id = t.flock_id WHERE t.transaction_id = ? AND t.deleted_at IS NULL'
        );
        $statement->execute([$transactionId]);
        $transaction = $statement->fetch();
    }
    if ($transaction === false || $transaction === null) {
        set_flash('danger', 'The selected transaction could not be found.');
        redirect_to('index.php');
    }

    $documentsStatement = $pdo->prepare('SELECT * FROM transaction_documents WHERE transaction_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC');
    $documentsStatement->execute([$transactionId]);
    $documents = $documentsStatement->fetchAll();

    $activeNavigation = $transaction['transaction_type'] === 'Expense' ? 'ais_transactions_expense' : 'ais_transactions_income';
    $pageTitle = 'Transaction Detail';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">TRANSACTIONS</div>
        <h1>Transaction Detail</h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to transactions</a>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <section class="content-panel mb-3">
                <div class="panel-heading d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Transaction information</h2>
                    <span class="badge text-bg-<?= $transaction['transaction_type'] === 'Income' ? 'success' : 'danger' ?>"><?= h($transaction['transaction_type']) ?></span>
                </div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-sm-6"><div class="detail-label">Date</div><div class="detail-value"><?= h($transaction['transaction_date']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Amount</div><div class="detail-value fs-5 fw-bold">₱<?= money($transaction['amount']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Category</div><div class="detail-value"><?= h($transaction['category']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Payment method</div><div class="detail-value"><?= h($transaction['payment_method']) ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Reference number</div><div class="detail-value"><?= h($transaction['reference_number'] ?: '—') ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Related flock/batch</div><div class="detail-value"><?= h($transaction['batch_code'] ?? '—') ?></div></div>
                        <div class="col-sm-6"><div class="detail-label">Status</div><div class="detail-value"><span class="badge text-bg-<?= h(ais_transaction_status_badge($transaction['status'])) ?>"><?= h($transaction['status']) ?></span></div></div>
                        <div class="col-sm-6"><div class="detail-label">Recorded on</div><div class="detail-value"><?= h((string) $transaction['created_at']) ?></div></div>
                        <div class="col-12"><div class="detail-label">Description</div><div class="detail-value"><?= h($transaction['description'] ?: '—') ?></div></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $transaction['transaction_id'] ?>">Edit</a>
                        <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this transaction to the Recycle Bin?\nIt can be restored later from the AIS Recycle Bin.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id'] ?>">
                            <button class="btn btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="content-panel mb-3">
                <div class="panel-heading"><h2 class="h5 mb-0">Supporting documents</h2></div>
                <div class="panel-body">
                    <?php if ($documents === []): ?>
                        <p class="text-secondary">No supporting documents uploaded yet.</p>
                    <?php else: ?>
                        <ul class="list-group mb-3">
                            <?php foreach ($documents as $document): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                    <div>
                                        <span class="badge text-bg-secondary"><?= h($document['document_type']) ?></span>
                                        <a class="ms-2" href="<?= h(app_url('/ais/transactions/download.php')) ?>?id=<?= (int) $document['document_id'] ?>"><?= h($document['file_name']) ?></a>
                                        <div class="small text-secondary">Uploaded <?= h((string) $document['uploaded_at']) ?></div>
                                    </div>
                                    <form method="post" action="index.php?action=delete_document" onsubmit="return confirm('Move this document to the Recycle Bin?\nIt can be restored later.');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="document_id" value="<?= (int) $document['document_id'] ?>">
                                        <input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Remove document"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="post" action="index.php?action=upload_document" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id'] ?>">
                        <div class="col-12">
                            <label class="form-label" for="document_type">Document type</label>
                            <select class="form-select" id="document_type" name="document_type">
                                <?php foreach (AIS_DOCUMENT_TYPES as $type): ?><option value="<?= h($type) ?>"><?= h($type) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="document">File (PDF, JPG, or PNG — max 5 MB)</label>
                            <input class="form-control" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                        <div class="col-12"><button class="btn btn-primary w-100" type="submit">Upload document</button></div>
                    </form>
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
$typeFilter = trim((string) ($_GET['type'] ?? ''));
if ($typeFilter !== '' && !in_array($typeFilter, AIS_TRANSACTION_TYPES, true)) {
    $typeFilter = '';
}
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, AIS_TRANSACTION_STATUSES, true)) {
    $statusFilter = '';
}
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'date_desc'));
if (!in_array($sort, ['date_desc', 'date_asc', 'amount_desc', 'amount_asc'], true)) {
    $sort = 'date_desc';
}

// Filters shared by both the list and the totals bar (everything except status,
// since totals always exclude Cancelled regardless of the status filter chosen).
$commonConditions = ['t.deleted_at IS NULL'];
$commonParameters = [];
if ($typeFilter !== '') {
    $commonConditions[] = 't.transaction_type = ?';
    $commonParameters[] = $typeFilter;
}
if ($categoryFilter !== '') {
    $commonConditions[] = 't.category = ?';
    $commonParameters[] = $categoryFilter;
}
if ($dateFrom !== '' && ais_valid_date($dateFrom)) {
    $commonConditions[] = 't.transaction_date >= ?';
    $commonParameters[] = $dateFrom;
}
if ($dateTo !== '' && ais_valid_date($dateTo)) {
    $commonConditions[] = 't.transaction_date <= ?';
    $commonParameters[] = $dateTo;
}
if ($search !== '') {
    $commonConditions[] = '(t.category LIKE ? OR t.description LIKE ? OR t.reference_number LIKE ?)';
    $like = '%' . $search . '%';
    $commonParameters[] = $like;
    $commonParameters[] = $like;
    $commonParameters[] = $like;
}

$conditions = $commonConditions;
$parameters = $commonParameters;
if ($statusFilter !== '') {
    $conditions[] = 't.status = ?';
    $parameters[] = $statusFilter;
}
$whereSql = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));
$orderSql = match ($sort) {
    'date_asc' => 'ORDER BY t.transaction_date ASC, t.transaction_id ASC',
    'amount_desc' => 'ORDER BY t.amount DESC, t.transaction_date DESC',
    'amount_asc' => 'ORDER BY t.amount ASC, t.transaction_date DESC',
    default => 'ORDER BY t.transaction_date DESC, t.transaction_id DESC',
};

$records = [];
$categories = [];
$totalsRow = ['income' => 0.0, 'expense' => 0.0];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $statement = $pdo->prepare(
            "SELECT t.*, f.batch_code FROM financial_transactions t LEFT JOIN flocks f ON f.flock_id = t.flock_id $whereSql $orderSql"
        );
        $statement->execute($parameters);
        $records = $statement->fetchAll();

        // Totals always exclude Cancelled transactions, matching the dashboard's rule,
        // regardless of which status the list itself is filtered to show.
        $totalsWhere = implode(' AND ', array_merge($commonConditions, ["t.status <> 'Cancelled'"]));
        $totalsStatement = $pdo->prepare("SELECT transaction_type, COALESCE(SUM(amount), 0) AS total FROM financial_transactions t WHERE $totalsWhere GROUP BY transaction_type");
        $totalsStatement->execute($commonParameters);
        foreach ($totalsStatement->fetchAll() as $row) {
            if ($row['transaction_type'] === 'Income') {
                $totalsRow['income'] = (float) $row['total'];
            } else {
                $totalsRow['expense'] = (float) $row['total'];
            }
        }

        $categories = $pdo->query("SELECT DISTINCT category FROM financial_transactions WHERE deleted_at IS NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $exception) {
        error_log('AIS transaction list query failed: ' . $exception->getMessage());
        $databaseError = 'Transactions could not be loaded because of a database error.';
    }
}

$activeNavigation = $typeFilter === 'Income' ? 'ais_transactions_income' : ($typeFilter === 'Expense' ? 'ais_transactions_expense' : 'ais_transactions_all');
$pageTitle = 'Transactions';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">AUTOMATED INCOME AND EXPENSE RECORDING</div>
    <h1>Transactions</h1>
    <p>Record and review income and expense transactions.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Total Income (filtered)</div>
            <div class="stat-value text-success">₱<?= money($totalsRow['income']) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Total Expenses (filtered)</div>
            <div class="stat-value text-danger">₱<?= money($totalsRow['expense']) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div class="stat-label">Net Income (filtered)</div>
            <?php $filteredNet = $totalsRow['income'] - $totalsRow['expense']; ?>
            <div class="stat-value <?= $filteredNet >= 0 ? 'text-success' : 'text-danger' ?>">₱<?= money($filteredNet) ?></div>
        </div>
    </div>
</div>

<section class="content-panel mb-4">
    <div class="panel-heading"><h2 class="h5 mb-0">Search &amp; filter</h2></div>
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" placeholder="Category, description, reference #" value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    <option value="Income" <?= $typeFilter === 'Income' ? 'selected' : '' ?>>Income</option>
                    <option value="Expense" <?= $typeFilter === 'Expense' ? 'selected' : '' ?>>Expense</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= h($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (AIS_TRANSACTION_STATUSES as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date range</label>
                <div class="d-flex gap-1">
                    <input class="form-control" type="date" name="date_from" value="<?= h($dateFrom) ?>">
                    <input class="form-control" type="date" name="date_to" value="<?= h($dateTo) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="sort">Sort by</label>
                <select class="form-select" id="sort" name="sort">
                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date (newest first)</option>
                    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Date (oldest first)</option>
                    <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Amount (highest first)</option>
                    <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Amount (lowest first)</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button>
                <a class="btn btn-outline-secondary" href="index.php" title="Clear filters" aria-label="Clear filters">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Transaction records</h2>
        <a class="btn btn-primary btn-sm" href="index.php?action=add<?= $typeFilter !== '' ? '&type=' . h($typeFilter) : '' ?>">Add transaction</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th class="text-end">Amount</th><th>Payment</th><th>Flock/Batch</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No transactions match the current filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= h($record['transaction_date']) ?></td>
                            <td><span class="badge text-bg-<?= $record['transaction_type'] === 'Income' ? 'success' : 'danger' ?>"><?= h($record['transaction_type']) ?></span></td>
                            <td><?= h($record['category']) ?></td>
                            <td><?= h(mb_strimwidth((string) $record['description'], 0, 40, '…')) ?></td>
                            <td class="text-end">₱<?= money($record['amount']) ?></td>
                            <td><?= h($record['payment_method']) ?></td>
                            <td><?= h($record['batch_code'] ?? '—') ?></td>
                            <td><span class="badge text-bg-<?= h(ais_transaction_status_badge($record['status'])) ?>"><?= h($record['status']) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="index.php?action=view&amp;id=<?= (int) $record['transaction_id'] ?>">View</a>
                                <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $record['transaction_id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
