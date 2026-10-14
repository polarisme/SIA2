<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_audit';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'Audit Trail';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Audit trail database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$search = trim((string) ($_GET['search'] ?? ''));
$actionFilter = trim((string) ($_GET['action_filter'] ?? ''));
$systemFilter = trim((string) ($_GET['system_filter'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'event_time_desc');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$sortColumns = [
    'event_time_desc' => 'event_time DESC',
    'event_time_asc' => 'event_time ASC',
    'action_asc' => 'action ASC, event_time DESC',
    'actor_asc' => 'actor_username ASC, event_time DESC',
];
$orderBy = $sortColumns[$sort] ?? $sortColumns['event_time_desc'];

$events = [];
$totalRows = 0;

if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $conditions = [];
        $parameters = [];

        if ($search !== '') {
            $conditions[] = '(actor_username LIKE ? OR target_username LIKE ? OR description LIKE ?)';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
            $parameters[] = '%' . $search . '%';
        }
        if ($actionFilter !== '' && in_array($actionFilter, USER_MANAGEMENT_AUDIT_ACTIONS, true)) {
            $conditions[] = 'action = ?';
            $parameters[] = $actionFilter;
        }
        if ($systemFilter !== '' && array_key_exists($systemFilter, SYSTEM_LABELS)) {
            $conditions[] = 'system_context = ?';
            $parameters[] = $systemFilter;
        }
        if ($dateFrom !== '') {
            $conditions[] = 'event_time >= ?';
            $parameters[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $conditions[] = 'event_time <= ?';
            $parameters[] = $dateTo . ' 23:59:59';
        }

        $whereClause = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $countStatement = $pdo->prepare("SELECT COUNT(*) FROM user_management_audit_log $whereClause");
        $countStatement->execute($parameters);
        $totalRows = (int) $countStatement->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listStatement = $pdo->prepare(
            "SELECT * FROM user_management_audit_log $whereClause ORDER BY $orderBy LIMIT $perPage OFFSET $offset"
        );
        $listStatement->execute($parameters);
        $events = $listStatement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Audit trail query failed: ' . $exception->getMessage());
        $databaseError = 'The audit trail could not be loaded because of a database error.';
    }
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));

function audit_query_string(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn ($value) => $value !== '' && $value !== null);

    return http_build_query($params);
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">USER MANAGEMENT</div>
    <h1>Audit Trail</h1>
    <p>A centralized record of every account and security event across PMS, AIS, Integrated, and User Management.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="audit-trail.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" value="<?= h($search) ?>" placeholder="Actor, target, description">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="action_filter">Action</label>
                <select class="form-select" id="action_filter" name="action_filter">
                    <option value="">All actions</option>
                    <?php foreach (USER_MANAGEMENT_AUDIT_ACTIONS as $actionOption): ?>
                        <option value="<?= h($actionOption) ?>" <?= $actionFilter === $actionOption ? 'selected' : '' ?>><?= h($actionOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="system_filter">System</label>
                <select class="form-select" id="system_filter" name="system_filter">
                    <option value="">All systems</option>
                    <?php foreach (SYSTEM_LABELS as $systemKey => $systemLabel): ?>
                        <option value="<?= h($systemKey) ?>" <?= $systemFilter === $systemKey ? 'selected' : '' ?>><?= h($systemLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_from">From</label>
                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_to">To</label>
                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-1 d-flex">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Go</button>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="sort">Sort by</label>
                <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                    <option value="event_time_desc" <?= $sort === 'event_time_desc' ? 'selected' : '' ?>>Newest first</option>
                    <option value="event_time_asc" <?= $sort === 'event_time_asc' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="action_asc" <?= $sort === 'action_asc' ? 'selected' : '' ?>>Action</option>
                    <option value="actor_asc" <?= $sort === 'actor_asc' ? 'selected' : '' ?>>Actor</option>
                </select>
            </div>
            <div class="col-12">
                <a class="btn btn-sm btn-outline-secondary" href="audit-trail.php">Clear filters</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Events</h2>
        <span class="text-secondary small"><?= number_format($totalRows) ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date / Time</th><th>Actor</th><th>Action</th><th>Target</th><th>System</th><th>Description</th><th>IP Address</th></tr></thead>
            <tbody>
                <?php if ($events === []): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-4">No audit events match these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td class="text-nowrap"><?= h((string) $event['event_time']) ?></td>
                            <td><?= h($event['actor_username'] ?? '—') ?></td>
                            <td><span class="badge text-bg-secondary"><?= h($event['action']) ?></span></td>
                            <td><?= h($event['target_username'] ?? '—') ?></td>
                            <td><?= $event['system_context'] ? h(SYSTEM_LABELS[$event['system_context']] ?? $event['system_context']) : '—' ?></td>
                            <td><?= h($event['description'] ?? '') ?></td>
                            <td><?= h($event['ip_address'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="panel-body">
            <nav aria-label="Audit trail pagination">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="audit-trail.php?<?= h(audit_query_string(['page' => $p])) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
