<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'ais';
$activeNavigation = 'ais_recycle_bin';
$appBrandName = 'YIELD Accounting Information System';
$appFooterName = 'YIELD Accounting Information System';

if (!is_poultry_admin()) {
    http_response_code(403);
    $pageTitle = 'Recycle Bin Access Denied';
    require __DIR__ . '/../../shared/includes/header.php';
    echo '<div class="alert alert-danger" role="alert">Only an administrator may access the Recycle Bin.</div>';
    require __DIR__ . '/../../shared/includes/footer.php';
    exit;
}

$types = ais_record_types();
$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS Recycle Bin database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}
$pageTitle = 'Recycle Bin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pdo instanceof PDO) {
    http_response_code(503);
    exit('The Recycle Bin is temporarily unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }
    $type = (string) ($_POST['record_type'] ?? '');
    $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
    $operation = (string) ($_POST['operation'] ?? '');
    if (!isset($types[$type]) || $recordId === false || $recordId < 1 || !in_array($operation, ['restore', 'permanent_delete'], true)) {
        set_flash('danger', 'The requested recycle-bin operation is invalid.');
        redirect_to('index.php');
    }

    try {
        if ($operation === 'restore') {
            if (ais_restore_record($pdo, $type, $recordId)) {
                set_flash('success', 'Record restored to the active system.');
            } else {
                set_flash('danger', 'The selected record was not found in the Recycle Bin.');
            }
        } else {
            $result = ais_permanent_delete($pdo, $type, $recordId);
            if ($result['deleted']) {
                set_flash('success', 'Record permanently deleted.');
            } else {
                set_flash('danger', 'Permanent delete blocked: ' . implode('; ', $result['blockers']));
            }
        }
    } catch (PDOException $exception) {
        error_log('AIS recycle bin operation failed: ' . $exception->getMessage());
        set_flash('danger', 'The recycle-bin operation could not be completed.');
    }
    redirect_to('index.php');
}

$typeFilter = trim((string) ($_GET['record_type'] ?? ''));
$dateFilter = trim((string) ($_GET['deleted_date'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
if ($typeFilter !== '' && !isset($types[$typeFilter])) {
    $typeFilter = '';
}
if ($dateFilter !== '' && !ais_valid_date($dateFilter)) {
    $dateFilter = '';
}

$rows = [];
if ($pdo instanceof PDO) {
    try {
        foreach ($types as $type => $metadata) {
            if ($typeFilter !== '' && $typeFilter !== $type) {
                continue;
            }
            $table = $metadata['table'];
            $idColumn = $metadata['id'];
            $sql = "SELECT `$idColumn` AS record_id, ({$metadata['identity_sql']}) AS identity_value, deleted_at, deleted_by FROM `$table` WHERE deleted_at IS NOT NULL";
            $parameters = [];
            if ($dateFilter !== '') {
                $sql .= ' AND DATE(deleted_at) = ?';
                $parameters[] = $dateFilter;
            }
            if ($search !== '') {
                $sql .= " AND ({$metadata['identity_sql']}) LIKE ?";
                $parameters[] = '%' . $search . '%';
            }
            $query = $pdo->prepare($sql);
            $query->execute($parameters);
            foreach ($query->fetchAll() as $row) {
                $rows[] = [
                    'type' => $type,
                    'label' => $metadata['label'],
                    'record_id' => (int) $row['record_id'],
                    'identity' => (string) ($row['identity_value'] ?? ''),
                    'deleted_at' => (string) $row['deleted_at'],
                    'deleted_by' => (string) ($row['deleted_by'] ?? ''),
                ];
            }
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($right['deleted_at'], $left['deleted_at']));
    } catch (PDOException $exception) {
        error_log('AIS Recycle Bin query failed: ' . $exception->getMessage());
        $databaseError = 'Recycle Bin records could not be loaded because of a database error.';
    }
}

require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">SYSTEM</div>
    <h1>Recycle Bin</h1>
    <p>Restore deleted AIS records, or permanently remove them. Deleted records are excluded from every AIS total and report while here.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center gap-3">
        <h2 class="h5 mb-0">Deleted AIS records</h2>
        <span class="text-secondary small"><?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="panel-body border-bottom">
        <form method="get" action="index.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="record_type">Record type</label>
                <select class="form-select" id="record_type" name="record_type">
                    <option value="">All types</option>
                    <?php foreach ($types as $type => $metadata): ?><option value="<?= h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= h($metadata['label']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label" for="deleted_date">Date deleted</label><input class="form-control" type="date" id="deleted_date" name="deleted_date" value="<?= h($dateFilter) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="search">Search identifier</label><input class="form-control" id="search" name="search" value="<?= h($search) ?>" placeholder="Customer, supplier, category, file name..."></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1" type="submit">Search / Filter</button><a class="btn btn-outline-secondary" href="index.php" aria-label="Clear filters">Clear</a></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th scope="col">Record Type</th><th scope="col">Record ID</th><th scope="col">Identifier</th><th scope="col">Deleted Date</th><th scope="col">Deleted By</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-secondary py-5">No deleted records match these filters.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['label']) ?></td><td><?= (int) $row['record_id'] ?></td><td><?= h($row['identity']) ?></td><td><?= h($row['deleted_at']) ?></td><td><?= h($row['deleted_by']) ?></td>
                    <td class="text-nowrap">
                        <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Restore this <?= h($row['label']) ?> record?\nIdentifier: <?= h($row['identity']) ?>\nIt will return to the active system.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="record_type" value="<?= h($row['type']) ?>"><input type="hidden" name="record_id" value="<?= (int) $row['record_id'] ?>"><input type="hidden" name="operation" value="restore"><button class="btn btn-sm btn-outline-primary" type="submit">Restore</button>
                        </form>
                        <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('PERMANENTLY DELETE this <?= h($row['label']) ?> record?\nIdentifier: <?= h($row['identity']) ?>\nThis record cannot be restored after permanent deletion.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="record_type" value="<?= h($row['type']) ?>"><input type="hidden" name="record_id" value="<?= (int) $row['record_id'] ?>"><input type="hidden" name="operation" value="permanent_delete"><button class="btn btn-sm btn-danger" type="submit">Permanent Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
