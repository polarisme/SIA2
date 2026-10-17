<?php
declare(strict_types=1);

// Shared helpers for the Accounting Information System (AIS) modules.
// Mirrors the conventions already used by shared/includes/pms_records.php.

const AIS_TRANSACTION_TYPES = ['Income', 'Expense'];
const AIS_PAYMENT_METHODS = ['Cash', 'Bank Transfer', 'E-Wallet', 'Credit', 'Other'];
const AIS_TRANSACTION_STATUSES = ['Recorded', 'Pending', 'Cancelled'];
const AIS_DOCUMENT_TYPES = ['Receipt', 'Invoice', 'Official Receipt', 'Purchase Order', 'Other'];
const AIS_AR_AP_STATUSES = ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'];
const AIS_ACCOUNT_TYPES = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];

/**
 * Automated Income/Expense Recording (4.1): posts one financial_transactions
 * row for an AR/AP payment (Income for AR, Expense for AP) and one
 * ar_ap_payments history row, in the same transaction as the AR/AP record's
 * own amount_paid/status update — the caller is expected to already be
 * inside a transaction covering that update. $category falls back to a
 * generic label when the AR/AP record has no linked transaction to inherit
 * a category from. Returns the new financial_transactions.transaction_id.
 */
function ais_post_ar_ap_payment(
    PDO $pdo,
    string $ledger,
    int $recordId,
    float $amount,
    string $paymentMethod,
    ?string $category,
    ?int $flockId
): int {
    if (!in_array($ledger, ['AR', 'AP'], true)) {
        throw new InvalidArgumentException('Invalid ledger.');
    }
    $transactionType = $ledger === 'AR' ? 'Income' : 'Expense';
    $category = $category !== null && $category !== ''
        ? $category
        : ($ledger === 'AR' ? 'Accounts Receivable Collection' : 'Accounts Payable Settlement');
    $description = ($ledger === 'AR' ? 'Payment received for receivable #' : 'Payment made for payable #') . $recordId;

    $statement = $pdo->prepare(
        'INSERT INTO financial_transactions
            (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$transactionType, $category, $description, $amount, $flockId, $paymentMethod, 'Recorded']);
    $transactionId = (int) $pdo->lastInsertId();

    $historyStatement = $pdo->prepare(
        'INSERT INTO ar_ap_payments (ledger, record_id, amount, payment_date, payment_method, transaction_id, recorded_by)
         VALUES (?, ?, ?, CURDATE(), ?, ?, ?)'
    );
    $historyStatement->execute([$ledger, $recordId, $amount, $paymentMethod, $transactionId, current_username()]);

    return $transactionId;
}

/** Formats a decimal-ish value as a fixed 2-decimal money string (no currency symbol). */
function money(float|int|string $value): string
{
    return number_format((float) $value, 2);
}

/** Parses a user-submitted amount. Returns false when it is not a valid, positive amount with up to 2 decimals. */
function ais_parse_amount(string $raw): float|false
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw)) {
        return false;
    }
    $value = (float) $raw;

    return $value > 0 ? $value : false;
}

/** Parses a non-negative amount (allows 0, e.g. for a starting "amount paid"). */
function ais_parse_nonnegative_amount(string $raw): float|false
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw)) {
        return false;
    }

    return (float) $raw;
}

/** Strict Y-m-d date validation (rejects "2026-02-30" style overflow dates). */
function ais_valid_date(string $value): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $value;
}

function ais_transaction_status_badge(string $status): string
{
    return match ($status) {
        'Recorded' => 'success',
        'Pending' => 'warning',
        'Cancelled' => 'secondary',
        default => 'secondary',
    };
}

/** SQL CASE expression computing the live AR/AP status from amount_due/amount_paid/due_date.
 *  Used instead of trusting the stored `status` column, so "Overdue" is always accurate
 *  even if nobody has edited the record since its due date passed. */
function ar_ap_status_sql(): string
{
    return "CASE
        WHEN (amount_due - amount_paid) <= 0 THEN 'Paid'
        WHEN due_date < CURDATE() THEN 'Overdue'
        WHEN amount_paid > 0 THEN 'Partially Paid'
        ELSE 'Unpaid' END";
}

function ar_ap_effective_status(float $amountDue, float $amountPaid, string $dueDate): string
{
    $balance = round($amountDue - $amountPaid, 2);
    if ($balance <= 0) {
        return 'Paid';
    }
    if ($dueDate < date('Y-m-d')) {
        return 'Overdue';
    }

    return $amountPaid > 0 ? 'Partially Paid' : 'Unpaid';
}

function ar_ap_status_badge(string $status): string
{
    return match ($status) {
        'Paid' => 'success',
        'Overdue' => 'danger',
        'Partially Paid' => 'warning',
        default => 'secondary',
    };
}

/** Suggested categories for a transaction type, drawn from the existing chart of accounts. */
function ais_category_suggestions(PDO $pdo, string $transactionType): array
{
    $accountType = $transactionType === 'Income' ? 'Revenue' : 'Expense';
    $statement = $pdo->prepare("SELECT account_name FROM accounts WHERE account_type = ? AND status = 'Active' ORDER BY account_name");
    $statement->execute([$accountType]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Absolute filesystem path for a project-root-relative path (e.g. "ais/transactions/uploads/..."). */
function ais_absolute_path(string $relativePath): string
{
    return rtrim(realpath(__DIR__ . '/../..'), '/\\') . '/' . ltrim($relativePath, '/\\');
}

/**
 * Validates and stores an uploaded supporting document.
 * Returns ['ok' => bool, 'error' => ?string, 'file_name' => ?string, 'file_path' => ?string].
 * 'ok' => false with 'error' => null means "nothing was uploaded" (not a failure).
 */
function ais_store_uploaded_document(array $file): array
{
    $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => null, 'file_name' => null, 'file_path' => null];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The file could not be uploaded.', 'file_name' => null, 'file_path' => null];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The upload could not be verified.', 'file_name' => null, 'file_path' => null];
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'The file exceeds the 5 MB limit.', 'file_name' => null, 'file_path' => null];
    }

    $allowedExtensions = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $originalName = (string) $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset($allowedExtensions[$extension])) {
        return ['ok' => false, 'error' => 'Only PDF, JPG, or PNG files are allowed.', 'file_name' => null, 'file_path' => null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedType = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    $expectedType = $allowedExtensions[$extension];
    if ($detectedType !== $expectedType && !($expectedType === 'image/jpeg' && $detectedType === 'image/jpg')) {
        return ['ok' => false, 'error' => 'The file content does not match a supported document type.', 'file_name' => null, 'file_path' => null];
    }

    $relativeDirectory = 'ais/transactions/uploads/' . date('Y/m');
    $absoluteDirectory = ais_absolute_path($relativeDirectory);
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        return ['ok' => false, 'error' => 'The upload folder could not be created.', 'file_name' => null, 'file_path' => null];
    }

    // Uploaded receipts/invoices must never be directly web-reachable — only
    // through the authenticated download.php. Apache applies a .htaccess to
    // every subdirectory beneath it, so one at the uploads root protects the
    // dated subfolders too; this just re-creates it if it's ever missing
    // (e.g. a fresh clone, or it was deleted by accident).
    $uploadsRoot = ais_absolute_path('ais/transactions/uploads');
    $htaccessPath = $uploadsRoot . '/.htaccess';
    if (!is_file($htaccessPath)) {
        file_put_contents(
            $htaccessPath,
            "# Uploaded financial supporting documents are served only through the authenticated\n"
            . "# download.php script, never directly by URL. Block all direct HTTP access here.\n"
            . "Require all denied\n"
        );
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $relativePath = $relativeDirectory . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $absoluteDirectory . '/' . $storedName)) {
        return ['ok' => false, 'error' => 'The file could not be saved.', 'file_name' => null, 'file_path' => null];
    }

    return [
        'ok' => true,
        'error' => null,
        'file_name' => mb_strimwidth($originalName, 0, 250, ''),
        'file_path' => $relativePath,
    ];
}

/**
 * Deletes a financial transaction and its supporting documents. Any accounts_receivable /
 * accounts_payable rows that referenced it keep their own record but have the link cleared
 * (an invoice's obligation does not disappear just because its ledger entry was removed).
 */
function ais_delete_transaction(PDO $pdo, int $transactionId): bool
{
    $pdo->beginTransaction();
    try {
        $documentsStatement = $pdo->prepare('SELECT file_path FROM transaction_documents WHERE transaction_id = ?');
        $documentsStatement->execute([$transactionId]);
        $filePaths = $documentsStatement->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM transaction_documents WHERE transaction_id = ?')->execute([$transactionId]);
        $pdo->prepare('UPDATE accounts_receivable SET transaction_id = NULL WHERE transaction_id = ?')->execute([$transactionId]);
        $pdo->prepare('UPDATE accounts_payable SET transaction_id = NULL WHERE transaction_id = ?')->execute([$transactionId]);

        $deleteStatement = $pdo->prepare('DELETE FROM financial_transactions WHERE transaction_id = ? AND deleted_at IS NOT NULL');
        $deleteStatement->execute([$transactionId]);
        $deleted = $deleteStatement->rowCount() === 1;

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    if ($deleted) {
        foreach ($filePaths as $filePath) {
            if (is_string($filePath) && $filePath !== '') {
                @unlink(ais_absolute_path($filePath));
            }
        }
    }

    return $deleted;
}

// =============================================================================
// AIS Recycle Bin — soft delete / restore / permanent delete.
// Mirrors the existing PMS recycle-bin pattern (pms_records.php) so both
// systems behave consistently: DELETE marks a row deleted_at/deleted_by;
// RESTORE clears those two columns; PERMANENT DELETE (recycle bin only) is a
// real DELETE, with the same safe cascade ais_delete_transaction() already
// used, and physically removes a document's uploaded file.
// =============================================================================

function ais_record_types(): array
{
    return [
        'financial_transactions' => [
            'table' => 'financial_transactions', 'id' => 'transaction_id', 'label' => 'Financial Transaction',
            'identity_sql' => "CONCAT(transaction_type, ': ', category, ' (', transaction_date, ') \u{20b1}', amount)",
        ],
        'accounts_receivable' => [
            'table' => 'accounts_receivable', 'id' => 'ar_id', 'label' => 'Accounts Receivable',
            'identity_sql' => "CONCAT(customer_name, ' (Inv# ', COALESCE(invoice_number, '\u{2014}'), ')')",
        ],
        'accounts_payable' => [
            'table' => 'accounts_payable', 'id' => 'ap_id', 'label' => 'Accounts Payable',
            'identity_sql' => "CONCAT(supplier_name, ' (Inv# ', COALESCE(invoice_number, '\u{2014}'), ')')",
        ],
        'transaction_documents' => [
            'table' => 'transaction_documents', 'id' => 'document_id', 'label' => 'Transaction Document',
            'identity_sql' => "CONCAT(document_type, ': ', file_name)",
        ],
    ];
}

function ais_soft_delete(PDO $pdo, string $type, int $recordId): bool
{
    $types = ais_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE `$idColumn` = ? AND deleted_at IS NULL");
    $statement->execute([current_username(), $recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
    }
    audit_pms_action($pdo, 'SOFT_DELETE', $table, $recordId);

    return true;
}

function ais_restore_record(PDO $pdo, string $type, int $recordId): bool
{
    $types = ais_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL, deleted_by = NULL WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $statement->execute([$recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
    }
    audit_pms_action($pdo, 'RESTORE', $table, $recordId);

    return true;
}

/** Permanent delete is only ever called on a row already sitting in the recycle bin. */
function ais_permanent_delete(PDO $pdo, string $type, int $recordId): array
{
    $types = ais_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return ['deleted' => false, 'blockers' => ['Invalid record type or ID.']];
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $exists = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $exists->execute([$recordId]);
    if ($exists->fetchColumn() === false) {
        return ['deleted' => false, 'blockers' => ['The record is not in the Recycle Bin.']];
    }

    if ($type === 'financial_transactions') {
        // Reuses the existing safe cascade: removes any linked documents (and their
        // files) and clears the reference from any AR/AP row before deleting.
        $deleted = ais_delete_transaction($pdo, $recordId);
    } elseif ($type === 'transaction_documents') {
        $filePathStatement = $pdo->prepare('SELECT file_path FROM transaction_documents WHERE document_id = ?');
        $filePathStatement->execute([$recordId]);
        $filePath = $filePathStatement->fetchColumn();
        $statement = $pdo->prepare('DELETE FROM transaction_documents WHERE document_id = ? AND deleted_at IS NOT NULL');
        $statement->execute([$recordId]);
        $deleted = $statement->rowCount() === 1;
        if ($deleted && is_string($filePath) && $filePath !== '') {
            @unlink(ais_absolute_path($filePath));
        }
    } else {
        $statement = $pdo->prepare("DELETE FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
        $statement->execute([$recordId]);
        $deleted = $statement->rowCount() === 1;
    }

    if ($deleted) {
        audit_pms_action($pdo, 'PERMANENT_DELETE', $table, $recordId);

        return ['deleted' => true, 'blockers' => []];
    }

    return ['deleted' => false, 'blockers' => ['The record could not be deleted.']];
}
